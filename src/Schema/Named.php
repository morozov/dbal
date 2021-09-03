<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

/**
 * @template N of Name
 */
interface Named
{
    /**
     * @return N
     */
    public function getName(): Name;
}
