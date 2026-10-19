<?php

declare(strict_types=1);

namespace IranAccounting\Adapter;

use IranAccounting\Adapter\Reference\ReferenceProfileAdapter;

/**
 * Sepidar reference profile.
 *
 * REFERENCE PROFILE — NOT VERIFIED AGAINST A LIVE SEPIDAR INSTALLATION.
 *
 * Sepidar's available integration surface depends on version, modules and the
 * reseller. No endpoint or field name in this class is asserted to be real.
 * Fill configTemplate() from the installed version's API documentation and
 * verify the remote-id path against an actual response.
 */
final class SepidarAdapter extends ReferenceProfileAdapter
{
    public const PROFILE = 'sepidar';
}
