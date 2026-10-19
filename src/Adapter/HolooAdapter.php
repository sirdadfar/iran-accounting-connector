<?php

declare(strict_types=1);

namespace IranAccounting\Adapter;

use IranAccounting\Adapter\Reference\ReferenceProfileAdapter;

/**
 * Holoo reference profile.
 *
 * REFERENCE PROFILE — NOT VERIFIED AGAINST A LIVE HOLOO INSTALLATION.
 *
 * Some Holoo deployments are integrated through SQL Server rather than a
 * documented HTTP API. That route is schema-specific and is NOT implemented or
 * guessed here; SqlServerAdapter is an empty experimental skeleton. For this
 * HTTP profile, fill configTemplate() from the installed version's official
 * API/SOAP documentation.
 *
 * No endpoint or field name in this class is asserted to be real.
 */
final class HolooAdapter extends ReferenceProfileAdapter
{
    public const PROFILE = 'holoo';
}
