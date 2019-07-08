<?php
namespace Aerticket\DataAnonymizer\Command;

/*
 * This file is part of the Aerticket.DataAnonymizer package.
 *
 * (c) Contributors to the package
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Aerticket\DataAnonymizer\Service\AnonymizationService;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Annotations as Flow;

/**
 * Class AnonymizeCommandController
 */
class AnonymizeCommandController extends CommandController
{

    /**
     * @var AnonymizationService
     * @Flow\Inject()
     */
    protected $anonymizationService;

    /**
     * Anonymize all entities that exceed their maximum age
     */
    public function runCommand()
    {
        $this->outputLine('Anonymizing all entities that exceed their maximum age');
        $classNames = $this->anonymizationService->getAnonymizableClassNames();

        foreach ($classNames as $className) {
            $this->outputLine('Processing entities of type "%s"...', [$className]);
            $statistics = $this->anonymizationService->anonymize($className);
            $this->outputLine('Anonymized %s / %s expired entities.', [$statistics['processed'], $statistics['total']]);
        }
    }
}
