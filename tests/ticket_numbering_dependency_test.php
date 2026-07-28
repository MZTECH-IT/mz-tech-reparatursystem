<?php
declare(strict_types=1);

final class TicketTestStatement
{
    public function __construct(
        private TicketTestDatabase $database,
        private string $sql
    ) {}

    public function execute(array $parameters = []): bool
    {
        $this->database->executed[] = [
            'sql' => preg_replace('/\s+/', ' ', trim($this->sql)),
            'parameters' => $parameters,
        ];

        if (str_starts_with(ltrim($this->sql), 'INSERT INTO tickets')) {
            $this->database->lastId = 501;
        } elseif (str_starts_with(ltrim($this->sql), 'INSERT INTO ticket_comments')) {
            $this->database->lastId = 601;
        }

        return true;
    }

    public function fetch(int $mode = 0): array|false
    {
        if (str_contains($this->sql, 'FROM number_ranges')) {
            return [
                'doc_type' => 'TIC',
                'prefix' => 'TIC',
                'separator' => '-',
                'digits' => 6,
                'yearly_reset' => 1,
                'start_number' => 1,
                'current_year' => (int)date('Y'),
                'current_number' => 40,
            ];
        }

        return false;
    }
}

final class TicketTestDatabase
{
    public array $executed = [];
    public int $lastId = 0;
    private bool $transaction = false;

    public function prepare(string $sql): TicketTestStatement
    {
        return new TicketTestStatement($this, $sql);
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function beginTransaction(): bool
    {
        $this->transaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->transaction = false;
        return true;
    }

    public function lastInsertId(): string
    {
        return (string)$this->lastId;
    }
}

$ticketTestDatabase = new TicketTestDatabase();
$activityEvents = [];
$auditEvents = [];

function get_db(): TicketTestDatabase
{
    global $ticketTestDatabase;
    return $ticketTestDatabase;
}

function get_setting(string $key, string $default = ''): string
{
    return $default;
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function log_activity(string $action, string $entityType, int $entityId, string $details = ''): void
{
    global $activityEvents;
    $activityEvents[] = [$action, $entityType, $entityId, $details];
}

function portal_audit(
    string $eventType,
    string $actorType,
    ?int $actorId = null,
    ?string $entityType = null,
    ?int $entityId = null,
    array $metadata = []
): void {
    global $auditEvents;
    $auditEvents[] = [$eventType, $actorType, $actorId, $entityType, $entityId, $metadata];
}

require_once dirname(__DIR__) . '/private/tickets.php';

$result = ticket_create([
    'subject' => 'TEST Nummerierungsabhängigkeit',
    'description' => 'Lokale Simulation ohne Produktivdatenbank.',
    'priority' => 'niedrig',
    'customer_id' => 77,
    'customer_reference' => 'TEST-LOCAL-20260728',
], [
    'type' => 'customer',
    'ref' => 77,
]);

$errors = [];
$assert = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) $errors[] = $message;
};

$assert(function_exists('generate_document_number'), 'Nummerierungsfunktion wurde nicht geladen.');
$assert(($result['success'] ?? false) === true, 'Ticket-Simulation war nicht erfolgreich.');
$assert(($result['ticket_number'] ?? '') === 'TIC-' . date('Y') . '-000041', 'Ticketnummer wurde unerwartet formatiert.');
$assert(($result['id'] ?? 0) === 501, 'Simulierte Ticket-ID ist falsch.');
$assert(count($activityEvents) === 1, 'Aktivitätsprotokoll wurde nicht ausgelöst.');
$assert(count($auditEvents) === 1, 'Portal-Audit wurde nicht ausgelöst.');
$assert(
    count(array_filter(
        $ticketTestDatabase->executed,
        static fn(array $entry): bool => str_starts_with($entry['sql'], 'INSERT INTO tickets')
    )) === 1,
    'Ticket-INSERT wurde nicht genau einmal ausgeführt.'
);

echo 'TICKET_NUMBERING_DEPENDENCY_ASSERTIONS=7' . PHP_EOL;
echo 'TICKET_NUMBERING_DEPENDENCY_ERRORS=' . count($errors) . PHP_EOL;
foreach ($errors as $error) echo 'ERROR: ' . $error . PHP_EOL;
exit($errors ? 1 : 0);
