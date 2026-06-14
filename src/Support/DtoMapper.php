<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Support;

use ChrisJohnLeah\SageAccounting\Data\Address;
use ChrisJohnLeah\SageAccounting\Data\Business;
use ChrisJohnLeah\SageAccounting\Data\Contact;
use ChrisJohnLeah\SageAccounting\Data\ContactPerson;
use ChrisJohnLeah\SageAccounting\Data\PurchaseInvoice;
use ChrisJohnLeah\SageAccounting\Data\PurchaseInvoiceLineItem;
use ChrisJohnLeah\SageAccounting\Data\Reference;
use DateTimeImmutable;

/**
 * Maps the SDK's typed, readonly DTOs into curated JSON-serialisable arrays.
 *
 * The DTOs expose dozens of fields and have no toArray()/jsonSerialize(); these
 * mappers pick the fields worth surfacing to an agent and normalise nested
 * references, dates, and currency amounts into a stable snake_case shape.
 */
final class DtoMapper
{
    /**
     * @return array<string, mixed>
     */
    public static function contact(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'displayed_as' => $contact->displayedAs,
            'name' => $contact->name,
            'reference' => $contact->reference,
            'contact_types' => array_map(self::reference(...), $contact->contactTypes),
            'email' => $contact->email,
            'tax_number' => $contact->taxNumber,
            'balance' => $contact->balance,
            'credit_limit' => $contact->creditLimit,
            'credit_days' => $contact->creditDays,
            'currency' => self::reference($contact->currency),
            'is_active' => $contact->isActive,
            'main_address' => self::address($contact->mainAddress),
            'main_contact_person' => self::contactPerson($contact->mainContactPerson),
            'notes' => $contact->notes,
            'created_at' => self::date($contact->createdAt),
            'updated_at' => self::date($contact->updatedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function purchaseInvoice(PurchaseInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'displayed_as' => $invoice->displayedAs,
            'reference' => $invoice->reference,
            'vendor_reference' => $invoice->vendorReference,
            'contact' => [
                'id' => $invoice->contact?->id,
                'name' => $invoice->contactName ?? $invoice->contact?->name,
                'reference' => $invoice->contactReference,
            ],
            'date' => self::date($invoice->date),
            'due_date' => self::date($invoice->dueDate),
            'status' => self::reference($invoice->status),
            'currency' => self::reference($invoice->currency),
            'net_amount' => $invoice->netAmount,
            'tax_amount' => $invoice->taxAmount,
            'total_amount' => $invoice->totalAmount,
            'total_paid' => $invoice->totalPaid,
            'outstanding_amount' => $invoice->outstandingAmount,
            'notes' => $invoice->notes,
            'invoice_lines' => array_map(self::invoiceLine(...), $invoice->invoiceLines),
            'created_at' => self::date($invoice->createdAt),
            'updated_at' => self::date($invoice->updatedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function business(Business $business): array
    {
        return [
            'id' => $business->id,
            'displayed_as' => $business->displayedAs,
            'name' => $business->name,
            'address_line_1' => $business->addressLine1,
            'address_line_2' => $business->addressLine2,
            'city' => $business->city,
            'postal_code' => $business->postalCode,
            'region' => $business->region,
            'country' => $business->country === null ? null : [
                'id' => $business->country->id,
                'displayed_as' => $business->country->displayedAs,
            ],
            'telephone' => $business->telephone,
            'mobile' => $business->mobile,
            'website' => $business->website,
            'is_demo' => $business->isDemo,
            'created_at' => self::date($business->createdAt),
            'updated_at' => self::date($business->updatedAt),
        ];
    }

    /**
     * @return array{id: ?string, displayed_as: ?string}|null
     */
    private static function reference(?Reference $reference): ?array
    {
        if ($reference === null) {
            return null;
        }

        return ['id' => $reference->id, 'displayed_as' => $reference->displayedAs];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function address(?Address $address): ?array
    {
        if ($address === null) {
            return null;
        }

        return [
            'address_line_1' => $address->addressLine1,
            'address_line_2' => $address->addressLine2,
            'city' => $address->city,
            'postal_code' => $address->postalCode,
            'region' => $address->region,
            'country' => self::reference($address->country),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function contactPerson(?ContactPerson $person): ?array
    {
        if ($person === null) {
            return null;
        }

        return [
            'name' => $person->name,
            'email' => $person->email,
            'telephone' => $person->telephone,
            'mobile' => $person->mobile,
            'job_title' => $person->jobTitle,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function invoiceLine(PurchaseInvoiceLineItem $line): array
    {
        return [
            'description' => $line->description,
            'ledger_account' => self::reference($line->ledgerAccount),
            'quantity' => $line->quantity,
            'unit_price' => $line->unitPrice,
            'net_amount' => $line->netAmount,
            'tax_rate' => self::reference($line->taxRate),
            'tax_amount' => $line->taxAmount,
            'total_amount' => $line->totalAmount,
        ];
    }

    private static function date(?DateTimeImmutable $date): ?string
    {
        return $date?->format(DateTimeImmutable::ATOM);
    }
}
