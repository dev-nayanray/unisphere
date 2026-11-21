<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        return $invoice->student_id === $user->id || $user->hasRole('accounts') || $user->isAdmin();
    }

    /** Students pay their own invoices online. */
    public function pay(User $user, Invoice $invoice): bool
    {
        return $invoice->student_id === $user->id;
    }
}
