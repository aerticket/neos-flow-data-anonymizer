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
 * @DoctrineAnnotation\Target("PROPERTY")
 */
final class Anonymize
{
    /**
     * Anonymized value
     * @var mixed
     */
    public $anonymizedValue;

    /**
     * @param array $values
     * @throws \InvalidArgumentException
     */
    public function __construct(array $values)
    {
        $this->anonymizedValue = isset($values['anonymizedValue'])
            ? $values['anonymizedValue']
            : (isset($values['value']) ? $values['value'] : null);
    }
}
