<?php

/**
 * This file is part of the ApiGen (http://apigen.org)
 *
 * For the full copyright and license information, please view
 * the file license.md that was distributed with this source code.
 */

namespace ApiGen\Parser\Broker;

use ApiGen\Generator\Generator;
use ApiGen\Reflection\ReflectionClass;
use ApiGen\Reflection\ReflectionConstant;
use ApiGen\Reflection\ReflectionFunction;
use ApiGen\Reflection\ReflectionMethod;
use TokenReflection;
use TokenReflection\IReflectionConstant;
use TokenReflection\IReflectionFunction;
use TokenReflection\Broker;
use TokenReflection\Resolver;


/**
 * Customized TokenReflection broker backend.
 * Adds internal classes from @param, @var, @return, @throws annotations as well
 * as parent classes to the overall class list.
 *
 * @method TokenReflection\ReflectionNamespace[] getNamespaces()
 */
class Backend extends Broker\Backend\Memory
{

	/**
	 * @var array
	 */
	private $allClasses = array(
		self::TOKENIZED_CLASSES => array(),
		self::INTERNAL_CLASSES => array(),
		self::NONEXISTENT_CLASSES => array()
	);

	/**
	 * @var array
	 */
	private $declared = array();

	/**
	 * @var Generator
	 */
	private $generator;


	public function setGenerator(Generator $generator)
	{
		$this->generator = $generator;
	}


	/**
	 * Returns all constants from all namespaces.
	 * Load with generator...
	 *
	 * @return ReflectionConstant[]
	 */
	public function getConstants()
	{
		$generator = $this->generator;
		return array_map(function (IReflectionConstant $constant) use ($generator) {
			return new ReflectionConstant($constant, $generator);
		}, parent::getConstants());
	}


	/**
	 * Returns all functions from all namespaces.
	 * Load with generator...
	 *
	 * @return ReflectionFunction[]
	 */
	public function getFunctions()
	{
		$generator = $this->generator;
		return array_map(function (IReflectionFunction $function) use ($generator) {
			return new ReflectionFunction($function, $generator);
		}, parent::getFunctions());
	}


	/**
	 * Prepares and returns used class lists.
	 *
	 * @return ReflectionClass[]
	 */
	protected function parseClassLists()
	{
		$this->declared = array_flip(array_merge(get_declared_classes(), get_declared_interfaces()));

		foreach ($this->getNamespaces() as $namespace) {
			foreach ($namespace->getClasses() as $name => $ref) {
				$class = new ReflectionClass($ref, $this->generator);
				$this->allClasses[self::TOKENIZED_CLASSES][$name] = $class;
				if ( ! $class->isDocumented()) {
					continue;
				}

				$this->loadParentClassesAndInterfacesFromClassReflection($ref);
			}
		}

		/** @var ReflectionClass $class */
		foreach ($this->allClasses[self::TOKENIZED_CLASSES] as $class) {
			if ( ! $class->isDocumented()) {
				continue;
			}

			foreach ($class->getOwnMethods() as $method) {
				$this->processFunction($method);
			}

			foreach ($class->getOwnProperties() as $property) {
				$this->loadAnnotationFromReflection($class, $property->getAnnotations(), 'var');
			}
		}

		foreach ($this->getFunctions() as $function) {
			$this->processFunction($function);
		}

		array_walk_recursive($this->allClasses, function (&$reflection, $name, Generator $generator) {
			if ( ! $reflection instanceof ReflectionClass) {
				$reflection = new ReflectionClass($reflection, $generator);
			}
		}, $this->generator);

		return $this->allClasses;
	}


	/**
	 * Processes a function/method and adds classes from annotations to the overall class array.
	 *
	 * @param ReflectionMethod|ReflectionFunction $reflection
	 */
	private function processFunction($reflection)
	{
		$annotations = $reflection->getAnnotations();
		foreach (array('param', 'return', 'throws') as $annotation) {
			$this->loadAnnotationFromReflection($reflection, $annotations, $annotation);
		}

		foreach ($reflection->getParameters() as $parameter) {
			if ($hint = $parameter->getClassName()) {
				$this->addClass($hint);
			}
		}
	}


	/**
	 * @param string $name
	 */
	private function addClass($name)
	{
		$name = ltrim($name, '\\');

		if ( ! isset($this->declared[$name]) || $this->isClassLoaded($name)) {
			return FALSE;
		}

		$parameterClass = $this->getBroker()->getClass($name);

		if ($parameterClass->isInternal()) {
			$this->allClasses[self::INTERNAL_CLASSES][$name] = $parameterClass;
			foreach (array_merge($parameterClass->getInterfaces(), $parameterClass->getParentClasses()) as $parentClass) {
				if ( ! isset($this->allClasses[self::INTERNAL_CLASSES][$parentName = $parentClass->getName()])) {
					$this->allClasses[self::INTERNAL_CLASSES][$parentName] = $parentClass;
				}
			}

		} elseif ( ! $parameterClass->isTokenized()) {
			if ( ! isset($this->allClasses[self::NONEXISTENT_CLASSES][$name])) {
				$this->allClasses[self::NONEXISTENT_CLASSES][$name] = $parameterClass;
			}
		}
	}


	private function loadParentClassesAndInterfacesFromClassReflection(TokenReflection\ReflectionClass $ref)
	{
		foreach (array_merge($ref->getParentClasses(), $ref->getInterfaces()) as $parentName => $parentReflection) {
			/** @var TokenReflection\ReflectionClass $parentReflection */
			if ($parentReflection->isInternal()) {
				if ( ! isset($this->allClasses[self::INTERNAL_CLASSES][$parentName])) {
					$this->allClasses[self::INTERNAL_CLASSES][$parentName] = $parentReflection;
				}

			} elseif ( ! $parentReflection->isTokenized()) {
				if ( ! isset($this->allClasses[self::NONEXISTENT_CLASSES][$parentName])) {
					$this->allClasses[self::NONEXISTENT_CLASSES][$parentName] = $parentReflection;
				}
			}
		}
	}


	/**
	 * @param string $name
	 * @return bool
	 */
	private function isClassLoaded($name)
	{
		return isset($this->allClasses[self::TOKENIZED_CLASSES][$name])
			|| isset($this->allClasses[self::INTERNAL_CLASSES][$name])
			|| isset($this->allClasses[self::NONEXISTENT_CLASSES][$name]);
	}


	/**
	 * @param ReflectionClass|ReflectionMethod $reflection
	 * @param array $annotations
	 * @param string $name
	 */
	private function loadAnnotationFromReflection($reflection, $annotations = array(), $name)
	{
		if ( ! isset($annotations[$name])) {
			return;
		}

		foreach ($annotations[$name] as $doc) {
			foreach (explode('|', preg_replace('~\\s.*~', '', $doc)) as $name) {
				if ($name = rtrim($name, '[]')) {
					$name = $this->getClassFQN($name, $reflection);
					$this->addClass($name);
				}
			}
		}
	}


	/**
	 * @param string $name
	 * @param ReflectionClass|ReflectionMethod $reflection
	 * @return string
	 */
	private function getClassFQN($name, $reflection)
	{
		return Resolver::resolveClassFQN($name, $reflection->getNamespaceAliases(), $reflection->getNamespaceName());
	}

}
