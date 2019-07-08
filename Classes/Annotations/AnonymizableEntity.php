<?php
namespace Aerticket\DataAnonymizer\Annotations;

/*
 * This file is part of the Aerticket.DataAnonymizer package.
 *
 * (c) Contributors to the package
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Doctrine\Common\Annotations\Annotation as DoctrineAnnotation;

/**
 * @Annotation
 * @DoctrineAnnotation\Target("CLASS")
 */
final class AnonymizableEntity
{
    /**
     * The property name of the date property that should be considered when calculated the age of the entity
     * @var string
     */
    public $referenceDate;

    /**
     * The time period after which the entity should be anonymized
     * @var array
     */
    public $anonymizeAfter;

    /**
     * @param array $values
     * @throws \InvalidArgumentException
     */
    public function __construct(array $values)
    {
        if (!isset($values['referenceDate'])) {
            throw new \InvalidArgumentException('A AnonymizableEntity annotation must specify a referenceDate.', 1562579216);
        }
        $this->referenceDate = $values['referenceDate'];
        $this->anonymizeAfter = isset($values['anonymizeAfter']) ? $values['anonymizeAfter'] : null;
    }
}
