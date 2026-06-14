<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Tools;

use ChrisJohnLeah\SageAccounting\Data\Contact;
use ChrisJohnLeah\SageAccounting\Mcp\Support\ApiError;
use ChrisJohnLeah\SageAccounting\Mcp\Support\DtoMapper;
use ChrisJohnLeah\SageAccounting\Requests\Contacts\PostContacts;
use ChrisJohnLeah\SageAccounting\Sage;
use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;

/**
 * MCP tool (WRITE — requires the `full_access` OAuth scope): create a contact.
 *
 * Registered by the server only when SAGE_SCOPES includes `full_access`.
 */
final class CreateContactTool
{
    public function __construct(private readonly Sage $sage)
    {
    }

    /**
     * Create a new contact (customer or supplier) in the connected Sage business.
     *
     * Requires the `full_access` OAuth scope.
     *
     * @param  string  $name  The contact's name (company or person).
     * @param  string  $contact_type_id  Contact type: "CUSTOMER" or "VENDOR" (supplier).
     * @param  string|null  $reference  A short reference/code for the contact.
     * @param  string|null  $email  Primary email address.
     * @param  string|null  $tax_number  VAT / tax registration number.
     * @param  string|null  $notes  Free-text notes.
     * @return array<string, mixed>
     */
    public function handle(
        string $name,
        string $contact_type_id,
        ?string $reference = null,
        ?string $email = null,
        ?string $tax_number = null,
        ?string $notes = null,
    ): array {
        $contact = array_filter([
            'name' => $name,
            'contact_type_ids' => [$contact_type_id],
            'reference' => $reference,
            'email' => $email,
            'tax_number' => $tax_number,
            'notes' => $notes,
        ], static fn (mixed $value): bool => $value !== null);

        try {
            $response = $this->sage->connector()->send(new PostContacts(['contact' => $contact]));
            $response->throw();
            $created = $response->dtoOrFail();

            if (! $created instanceof Contact) {
                throw new RuntimeException('Sage did not return the created contact.');
            }

            return ['contact' => DtoMapper::contact($created)];
        } catch (RequestException $exception) {
            throw new RuntimeException(ApiError::message($exception), previous: $exception);
        } catch (FatalRequestException $exception) {
            throw new RuntimeException('Could not reach Sage: '.$exception->getMessage(), previous: $exception);
        }
    }
}
