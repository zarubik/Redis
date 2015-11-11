<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.md that was distributed with this source code.
 */

namespace Kdyby\Redis\DI;

use Kdyby;
use Kdyby\Redis\RedisClient;
use Nette;
use Nette\DI\Config;
use Nette\DI\Compiler;
use Nette\DI\ContainerBuilder;
use Nette\Utils\Validators;



if (!class_exists('Nette\DI\CompilerExtension')) {
	class_alias('Nette\Config\CompilerExtension', 'Nette\DI\CompilerExtension');
	class_alias('Nette\Config\Compiler', 'Nette\DI\Compiler');
	class_alias('Nette\Config\Helpers', 'Nette\DI\Config\Helpers');
}

if (isset(Nette\Loaders\NetteLoader::getInstance()->renamed['Nette\Configurator']) || !class_exists('Nette\Configurator')) {
	unset(Nette\Loaders\NetteLoader::getInstance()->renamed['Nette\Configurator']); // fuck you
	class_alias('Nette\Config\Configurator', 'Nette\Configurator');
}

/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class RedisExtension extends Nette\DI\CompilerExtension
{

	const DEFAULT_SESSION_PREFIX = Kdyby\Redis\RedisSessionHandler::NS_NETTE;
	const PANEL_COUNT_MODE = 'count';
	
	/**
	 * @var array
	 */
	public $defaults = array(
		'journal' => FALSE,
		'storage' => FALSE,
		'session' => FALSE,
		'clients' => array(),
	);

	/**
	 * @var array
	 */
	public $clientDefaults = array(
		'host' => '127.0.0.1',
		'port' => NULL,
		'timeout' => 10,
		'database' => 0,
		'debugger' => '%debugMode%',
		'versionCheck' => TRUE,
		'auth' => NULL,
		'lockDuration' => 15,
	);

	/**
	 * @var array
	 */
	private $configuredClients = array();
	
	
	public function loadConfiguration()
	{
		$this->configuredClients = array();
		
		$builder = $this->getContainerBuilder();
		$config = self::fixClientConfig($this->getConfig($this->defaults + $this->clientDefaults));

		$this->buildClient(NULL, $config);
		
		$builder->addDefinition($this->prefix('driver'))
			->setClass(class_exists('Redis') ? 'Kdyby\Redis\Driver\PhpRedisDriver' : 'Kdyby\Redis\IRedisDriver')
			->setFactory($this->prefix('@client') . '::getDriver');
		
		$this->loadJournal($config);
		$this->loadStorage($config);
		$this->loadSession($config);
		
		foreach ($config['clients'] as $name => $clientConfig) {
			$this->buildClient($name, $clientConfig);
		}
	}


	/**
	 * @param string $name
	 * @param array $config
	 * @return Nette\DI\ServiceDefinition
	 */
	protected function buildClient($name, $config)
	{
		$builder = $this->getContainerBuilder();
		
		$defaultConfig = $this->getConfig($this->clientDefaults);
		if ($parentName = Config\Helpers::takeParent($config)) {
			Nette\Utils\Validators::assertField($this->configuredClients, $parentName, 'array', "parent configuration '%', are you sure it's defined?");
			$defaultConfig = Config\Helpers::merge($this->configuredClients[$parentName], $defaultConfig);
		}
		$config = Config\Helpers::merge($config, $defaultConfig);
		$config = array_intersect_key(self::fixClientConfig($config), $this->clientDefaults);
		
		$client = $builder->addDefinition($clientName = $this->prefix(($name ? $name . '_' : '') . 'client'))
			->setClass('Kdyby\Redis\RedisClient', array(
				'host' => $config['host'],
				'port' => $config['port'],
				'database' => $config['database'],
				'timeout' => $config['timeout'],
				'auth' => $config['auth']
			));
		
		if (empty($builder->parameters[$this->name]['defaultClient'])) {
			$builder->parameters[$this->name]['defaultClient'] = $clientName;
			$this->configuredClients['default'] = $config;
			$builder->addDefinition($this->prefix('default_client'))
				->setClass('Kdyby\Redis\RedisClient')
				->setFactory('@' . $clientName)
				->setAutowired(FALSE);
			
		} else {
			$client->setAutowired(FALSE);
		}
		
		$this->configuredClients[$name] = $config;
		
		$client->addSetup('setupLockDuration', array($config['lockDuration']));
		$client->addTag('redis.client');
		
		if (array_key_exists('debugger', $config) && $config['debugger']) {
			
			$builder->addDefinition($panelName = $clientName . '.panel')
				->setClass('Kdyby\Redis\Diagnostics\Panel')
				->setFactory('Kdyby\Redis\Diagnostics\Panel::register')
				->addSetup('$renderPanel', array($config['debugger'] !== self::PANEL_COUNT_MODE))
				->addSetup('$name', array($name ?: 'default'));
			
			$client->addSetup('setPanel', array('@' . $panelName));
		}
		
		return $client;
	}
	
	protected function loadJournal(array $config)
	{
		if (!$config['journal']) {
			return;
		}
		
		$builder = $this->getContainerBuilder();
		
		$journalConfig = Nette\DI\Config\Helpers::merge(is_array($config['journal']) ? $config['journal'] : array(), array(
				'prefix' => NULL,
			));
			
			$cacheJournal = $builder->addDefinition($this->prefix('cacheJournal'))
				->setClass('Kdyby\Redis\RedisLuaJournal');

			if($journalConfig['prefix']) {
				$cacheJournal->addSetup('setPrefix', array($journalConfig['prefix']));
			}
			
			// overwrite
			$builder->removeDefinition('nette.cacheJournal');
			$builder->addDefinition('nette.cacheJournal')->setFactory($this->prefix('@cacheJournal'));
	}
	
	protected function loadStorage(array $config)
	{
		if (!$config['storage']) {
			return;
		}
		
		$builder = $this->getContainerBuilder();
		
		$storageConfig = Nette\DI\Config\Helpers::merge(is_array($config['storage']) ? $config['storage'] : array(), array(
			'locks' => TRUE,
			'prefix' => NULL,
		));

		$cacheStorage = $builder->addDefinition($this->prefix('cacheStorage'))
			->setClass('Kdyby\Redis\RedisStorage');

		if (!$storageConfig['locks']) {
			$cacheStorage->addSetup('disableLocking');
		}

		if ($storageConfig['prefix']) {
			$cacheStorage->addSetup('setPrefix', array($storageConfig['prefix']));
		}

		$builder->removeDefinition('cacheStorage');
		$builder->addDefinition('cacheStorage')->setFactory($this->prefix('@cacheStorage'));
	}
	
	protected function loadSession(array $config)
	{
		if (!$config['session']) {
			return;
		}
		
		$builder = $this->getContainerBuilder();
		
		$sessionConfig = Nette\DI\Config\Helpers::merge(is_array($config['session']) ? $config['session'] : array(), array(
			'host' => $config['host'],
			'port' => $config['port'],
			'weight' => 1,
			'timeout' => $config['timeout'],
			'database' => $config['database'],
			'prefix' => self::DEFAULT_SESSION_PREFIX,
			'auth' => $config['auth'],
			'native' => TRUE,
			'lockDuration' => $config['lockDuration'],
		));

		if ($sessionConfig['native']) {
			$this->loadNativeSessionHandler($sessionConfig);

		} else {
			$builder->addDefinition($this->prefix('sessionHandler_client'))
				->setClass('Kdyby\Redis\RedisClient', array(
					'host' => $sessionConfig['host'],
					'port' => $sessionConfig['port'],
					'database' => $sessionConfig['database'],
					'timeout' => $sessionConfig['timeout'],
					'auth' => $sessionConfig['auth']
				))
				->addSetup('setupLockDuration', array($sessionConfig['lockDuration']))
				->setAutowired(FALSE);

			$builder->addDefinition($this->prefix('sessionHandler'))
				->setClass('Kdyby\Redis\RedisSessionHandler', array($this->prefix('@sessionHandler_client')));

			$builder->getDefinition('session')
				->addSetup('setStorage', array($this->prefix('@sessionHandler')));
		}
	}

	protected function loadNativeSessionHandler(array $session)
	{
		$builder = $this->getContainerBuilder();

		$params = array_intersect_key($session, array_flip(array('weight', 'timeout', 'database', 'prefix', 'auth', 'persistent')));
		if (substr($session['host'], 0, 1) === '/') {
			$savePath = $session['host'];

		} else {
			$savePath = sprintf('tcp://%s:%d', $session['host'], $session['port']);
		}

		$options = array(
			'saveHandler' => 'redis',
			'savePath' => $savePath . ($params ? '?' . http_build_query($params, '', '&') : ''),
		);

		foreach ($builder->getDefinition('session')->setup as $statement) {
			if ($statement->entity === 'setOptions') {
				$statement->arguments[0] = Nette\DI\Config\Helpers::merge($options, $statement->arguments[0]);
				unset($options);
				break;
			}
		}

		if (isset($options)) {
			$builder->getDefinition('session')
				->addSetup('setOptions', array($options));
		}
	}



	/**
	 * Verify, that redis is installed, working and has the right version.
	 */
	public function beforeCompile()
	{
		foreach ($this->configuredClients as $config) {
			if (!$config['versionCheck']) {
				continue;
			}
			
			$client = new RedisClient($config['host'], $config['port'], $config['database'], $config['timeout'], $config['auth']);
			$client->assertVersion();
			$client->close();
		}
	}


	protected static function fixClientConfig(array $config)
	{
		if ($config['host'][0] === '/') {
			$config['port'] = NULL; // sockets have no ports
			
		} elseif (!$config['port']) {
			$config['port'] = 6379;
		}
		
		return $config;
	}


	/**
	 * @param \Nette\Configurator $config
	 */
	public static function register(Nette\Configurator $config)
	{
		$config->onCompile[] = function ($config, Compiler $compiler) {
			$compiler->addExtension('redis', new RedisExtension());
		};
	}

}
