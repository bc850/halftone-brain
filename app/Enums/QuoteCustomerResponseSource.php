<?php

namespace App\Enums;

enum QuoteCustomerResponseSource: string
{
    case Customer = 'customer';
    case Employee = 'employee';
}
