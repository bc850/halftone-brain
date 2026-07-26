<?php

namespace App\Enums;

enum QuoteStatusTransitionSource: string
{
    case System = 'system';
    case User = 'user';
    case Approval = 'approval';
    case Customer = 'customer';
    case EmployeeOnBehalf = 'employee_on_behalf';
    case Clone = 'clone';
    case DealSync = 'deal_sync';
}
