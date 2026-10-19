<?php

declare(strict_types=1);

namespace IranAccounting;

final class Capabilities
{
    public const INVOICE_PUSH = 'invoice.push';
    public const PAYMENT_PUSH = 'payment.push';
    public const CUSTOMER_UPSERT = 'customer.upsert';
    public const INVOICE_FIND = 'invoice.find';
    public const HEALTH_CHECK = 'health.check';
    public const IDEMPOTENCY_HEADER = 'idempotency.header';
    public const DRY_RUN = 'dry_run';
    public const PROTOCOL = 'protocol';
}
