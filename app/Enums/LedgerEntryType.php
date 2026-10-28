<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
