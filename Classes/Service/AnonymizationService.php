<?php
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
     * @param string $className The class name of the model
     * @return Repository
     */
    protected function getRepositoryFor($className)
    {
        if ($this->repositoryClassNames === null) {
            $this->repositoryClassNames = $this->reflectionService->getAllSubClassNamesForClass(Repository::class);
        }

        foreach ($this->repositoryClassNames as $repositoryClassName) {
            /** @var Repository $repository */
            $repository = $this->objectManager->get($repositoryClassName);
            if ($repository->getEntityClassName() === $className) {
                return $repository;
            }
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
        $repository = $this->getRepositoryFor($className);
        $objects = $repository->findAll();
        $query = $objects->getQuery();

        /** @var AnonymizableEntity $entityAnnotation */
        $entityAnnotation = $this->reflectionService->getClassAnnotation($className, AnonymizableEntity::class);
        $anonymizedPropertyValues = $this->getAnonymizedPropertyValuesFor($className);

        // Build property constraints to retrieve only non anonymized entities
        $constraints = [];
        foreach ($anonymizedPropertyValues as $propertyName => $propertyValue) {
            $constraints[] = $query->logicalNot($query->equals($propertyName, $propertyValue));
        }

        // Only objects with a referenceDate before the anonymizeAfterDate should be anonymized
        $anonymizeAfter = $entityAnnotation->anonymizeAfter ?: $this->defaults['anonymizeAfter'];
        $anonymizeAfterDate = new \DateTime($anonymizeAfter . ' ago');
        $constraints[] = $query->lessThan($entityAnnotation->referenceDate, $anonymizeAfterDate);

        $query->matching($query->logicalAnd($constraints));
        $total = $query->count();

        $query->setLimit($limit);
        $query->setOrderings([$entityAnnotation->referenceDate => QueryInterface::ORDER_ASCENDING]);
        $matchingObjects = $query->execute();

        foreach ($matchingObjects as $object) {
            foreach ($anonymizedPropertyValues as $propertyName => $propertyValue) {
                ObjectAccess::setProperty($object, $propertyName, $propertyValue);
                $repository->update($object);
            }
        }

        return [
            'processed' => count($matchingObjects),
            'total' => $total,
        ];
    }
}
