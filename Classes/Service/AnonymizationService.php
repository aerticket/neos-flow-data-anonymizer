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
     * @param $className
     * @return Repository
     * @throws \Exception
     */
    protected function getRepositoryFor($className)
    {
        $repositoryClassName = $this->reflectionService->getClassSchema($className)->getRepositoryClassName();
        if ($repositoryClassName !== null) {
            /** @var Repository $repository */
            $repository = $this->objectManager->get($repositoryClassName);
            return $repository;
        }
        throw new \Exception(sprintf('No repository found for entity class "%s"', $className));
    }

    /**
     * @param $className
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
     * Anonymize all entities of a given class name that exceed their maximum age
     *
     * @param string $className The class name of the entities that should be anonymized
     * @param int $limit Anonymize only this number of entities per entity class and run
     * @throws \Neos\Flow\Persistence\Exception\InvalidQueryException
     */
    public function anonymize($className, $limit = 100)
    {
        $this->logger->debug(sprintf('Anonymizing entites of class %s', $className));

        $repository = $this->getRepositoryFor($className);
        $objects = $repository->findAll();
        $query = $objects->getQuery();

        /** @var AnonymizableEntity $entityAnnotation */
        $entityAnnotation = $this->reflectionService->getClassAnnotation($className, AnonymizableEntity::class);
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
            foreach ($anonymizedPropertyValues as $propertyName => $propertyValue) {
                if (ObjectAccess::isPropertySettable($object, $propertyName)) {
                    ObjectAccess::setProperty($object, $propertyName, $propertyValue);
                } else {
                    ObjectAccess::setProperty($object, $propertyName, $propertyValue, true);
                }
            }
            $repository->update($object);
        }

        $this->logger->info(sprintf('%s of %s entities have been anonymized in this run.', count($matchingObjects), $total));
    }
}
