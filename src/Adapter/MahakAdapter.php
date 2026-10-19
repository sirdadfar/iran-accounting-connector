<?php

declare(strict_types=1);

namespace IranAccounting\Adapter;

use IranAccounting\Adapter\Reference\ReferenceProfileAdapter;

/**
 * Mahak reference profile.
 *
 * REFERENCE PROFILE — NOT VERIFIED AGAINST A LIVE MAHAK INSTALLATION.
 *
 * No endpoint, SOAP operation or field name in this class is asserted to be
 * real. Mahak deployments differ by version and add-on, and a stable public
 * contract was not available while writing this library. Fill
 * configTemplate() using the customer's installed version API/SOAP docs.
 *
 * The class is intentionally a thin subclass of GenericWebserviceAdapter: the
 * tested behaviour lives there; this file only supplies a profile name and a
 * template of placeholders.
 */
final class MahakAdapter extends ReferenceProfileAdapter
{
    public const PROFILE = 'mahak';
}
