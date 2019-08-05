<?php
declare(strict_types=1);

namespace Aerticket\DataAnonymizer\Service;

/*
 * This file is part of the Aerticket.DataAnonymizer package.
 *
 * (c) Contributors to the package
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Aerticket\DataAnonymizer\Annotations\AnonymizableEntity;
use Aerticket\DataAnonymizer\Annotations\Anonymize;
use Aerticket\DataAnonymizer\AnonymizationException;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Persistence\QueryInterface;
use Neos\Flow\Persistence\Repository;
use Neos\Flow\Reflection\ReflectionService;
use Neos\Flow\Annotations as Flow;
use Neos\Utility\ObjectAccess;
use Psr\Log\LoggerInterface;

/**
 * Class AnonymizationService
 */
class AnonymizationService
{

    /**
     * @Flow\Inject
     * @var ReflectionService
     */
    protected $reflectionService;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var array
     */
    protected $repositoryClassNames;

    /**
     * @var array
     * @Flow\InjectConfiguration(path="defaults")
     */
    protected $defaults;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param string $className
     * @return Repository
     * @throws AnonymizationException Thrown if no repository associated with the given class name was found
     */
    protected function getRepositoryFor($className)
    {
        $repositoryClassName = $this->reflectionService->getClassSchema($className)->getRepositoryClassName();
        if ($repositoryClassName !== null) {
            /** @var Repository $repository */
            $repository = $this->objectManager->get($repositoryClassName);
            return $repository;
        }
        throw new AnonymizationException(sprintf('No repository found for entity class "%s"', $className), 1565020952);
    }

    /**
     * @param string $className
     * @return array
     */
    protected function getAnonymizedPropertyValuesFor($className)
    {
        $anonymizedPropertyValues = [];
        $propertyNames = $this->reflectionService->getPropertyNamesByAnnotation($className, Anonymize::class);
        foreach ($propertyNames as $propertyName) {
            /** @var Anonymize $propertyAnnotation */
            $propertyAnnotation = $this->reflectionService->getPropertyAnnotation($className, $propertyName, Anonymize::class);
            $propertyData = $this->reflectionService->getClassSchema($className)->getProperty($propertyName);
            $defaultValue = $this->defaults['anonymizedValues'][$propertyData['type']] ?? $this->defaults['anonymizedValues']['fallback'];
            $anonymizedPropertyValues[$propertyName] = $propertyAnnotation->anonymizedValue ?: $defaultValue;
        }
        return $anonymizedPropertyValues;
    }

    /**
     * Check whether a class is properly annotated as AnonymizableEntity and has anonymizable properties
     *
     * @param string $className
     * @return bool
     */
    protected function isAnonymizable($className)
    {
        return in_array($className, $this->getAnonymizableClassNames());
    }

    /**
     * Return all class names that can be anonymized (i.e. proper annotation and at least one anonymizable property)
     *
     * @return array
     */
    public function getAnonymizableClassNames()
    {
        $classNames = $this->reflectionService->getClassNamesByAnnotation(AnonymizableEntity::class);
        return array_filter($classNames, function($className) {
            // Only process this className if at least one property should be anonymized
            return count($this->getAnonymizedPropertyValuesFor($className)) > 0;
        });
    }

    /**
     * Anonymize a given entity.
     *
     * @param object $entity
     * @param bool $update Whether to update the anonymized entity in it's repository
     * @return object The anonymized entity
     * @throws AnonymizationException
     */
    public function anonymizeEntity($entity, $update = true)
    {
        $className = get_class($entity);
        if (!$this->isAnonymizable($className)) {
            throw new AnonymizationException(
                sprintf('The class %s is not annotated as %s or does not have any anonymizable properties.', $className, AnonymizableEntity::class),
                1563899393
            );
        }
        $anonymizedPropertyValues = $this->getAnonymizedPropertyValuesFor($className);
        $this->anonymizeProperties($entity, $anonymizedPropertyValues);
        if ($update) {
            try {
                $repository = $this->getRepositoryFor($className);
                $repository->update($entity);
            } catch (\Exception $e) {
                throw new AnonymizationException('Could not determine a repository for the given entity', 1563899697, $e);
            }
        }
        return $entity;
    }

    /**
     * Anonymize all entities of a given class name that exceed their maximum age
     *
     * @param string $className The class name of the entities that should be anonymized
     * @param int $limit Anonymize only this number of entities per entity class and run
     * @throws \Neos\Flow\Persistence\Exception\InvalidQueryException
     * @throws AnonymizationException
     */
    public function anonymize($className, $limit = 100)
    {
        /** @var AnonymizableEntity $entityAnnotation */
        $entityAnnotation = $this->reflectionService->getClassAnnotation($className, AnonymizableEntity::class);
        if ($entityAnnotation === null) {
            throw new AnonymizationException(
                sprintf('The class %s is not annotated as %s. Maybe you are missing an import.', $className, AnonymizableEntity::class),
                1563899363
                );
        }

        $this->logger->debug(sprintf('Anonymizing entites of class %s', $className));

        $repository = $this->getRepositoryFor($className);
        $objects = $repository->findAll();
        $query = $objects->getQuery();

        $anonymizedPropertyValues = $this->getAnonymizedPropertyValuesFor($className);

        // Build property constraints to retrieve only non anonymized entities
        $propertyConstraints = [];
        foreach ($anonymizedPropertyValues as $propertyName => $propertyValue) {
            $propertyConstraints[] = $query->equals($propertyName, $propertyValue);
        }

        // Only objects with a referenceDate before the anonymizeAfterDate should be anonymized
        $anonymizeAfter = $entityAnnotation->anonymizeAfter ?: $this->defaults['anonymizeAfter'];
        $anonymizeAfterDate = new \DateTime($anonymizeAfter . ' ago');

        $query->matching(
            $query->logicalAnd(
                $query->logicalNot(
                    $query->logicalAnd($propertyConstraints)
                ),
                $query->lessThan($entityAnnotation->referenceDate, $anonymizeAfterDate)
            )
        );

        $total = $query->count();

        if ($total === 0) {
            $this->logger->info(sprintf('No entities to anonymize for class %s', $className));
            return;
        }

        $this->logger->info(sprintf('%s entites to anonymize for class %s', $total, $className));

        $query->setLimit($limit);
        $query->setOrderings([$entityAnnotation->referenceDate => QueryInterface::ORDER_ASCENDING]);
        $matchingObjects = $query->execute();

        foreach ($matchingObjects as $object) {
            $this->anonymizeProperties($object, $anonymizedPropertyValues);
            $repository->update($object);
        }

        $this->logger->info(sprintf('%s of %s entities have been anonymized in this run.', count($matchingObjects), $total));
    }

    /**
     * Actually anonymizes the properties of a given entity
     *
     * @param object $object An entity
     * @param array $propertyValues Values as determined by {@see getAnonymizedPropertyValuesFor}
     */
    protected function anonymizeProperties($object, $propertyValues)
    {
        foreach ($propertyValues as $propertyName => $propertyValue) {
            if (ObjectAccess::isPropertySettable($object, $propertyName)) {
                ObjectAccess::setProperty($object, $propertyName, $propertyValue);
            } else {
                ObjectAccess::setProperty($object, $propertyName, $propertyValue, true);
            }
        }
    }
}
