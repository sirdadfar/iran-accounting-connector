<?php

declare(strict_types=1);

namespace IranAccounting\Domain;

final class Customer
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $nationalId = null,
        public readonly ?string $mobile = null,
        public readonly ?string $address = null,
        public readonly ?string $economicCode = null,
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'national_id' => $this->nationalId,
            'mobile' => $this->mobile,
            'address' => $this->address,
            'economic_code' => $this->economicCode,
        ];
    }
}
