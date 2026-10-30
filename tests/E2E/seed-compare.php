<?php

declare(strict_types=1);

/**
 * One comparison, built to be lopsided on purpose.
 *
 * The alignment this seeds for is only interesting where the two invoices disagree about how tall a
 * row is, so every shape that causes that is present at once: a line whose options wrap on the
 * original and not on the demo, a line where the demo is the taller one, a line only the original
 * has, a line only the demo has, and a line the provider removed. A tidy fixture would prove nothing.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Auth\Infrastructure\NativePasswordHasher;
use App\Environment;
use App\Shared\Infrastructure\Db\DbConnectionFactory;
use App\Shared\Infrastructure\Db\DbParams;
use App\Shared\Order58\Items\ItemCanonicalizer;
use Yiisoft\Cache\ArrayCache;
use Yiisoft\Db\Cache\SchemaCache;

Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
Environment::prepare();

$c = (new DbConnectionFactory(new DbParams(
    host: Environment::string('DB_HOST'),
    port: Environment::int('DB_PORT'),
    name: Environment::string('DB_NAME'),
    user: Environment::string('DB_USER'),
    password: Environment::string('DB_PASSWORD'),
    charset: Environment::string('DB_CHARSET'),
    socket: Environment::string('DB_SOCKET'),
), new SchemaCache(new ArrayCache())))->create();

const ADMIN = '__kf_e2e_compare_admin__';
const PASSWORD = 'E2EComparePassw0rd!secure';
const STORE = 987655910;
const SLUG = 'kf-e2e-compare-store';
const ORDER = 16800911;
const DEMO_ORDER = 16630911;
const PHONE = '19178530911';

require __DIR__ . '/purge-compare.php';

$now = gmdate('Y-m-d H:i:s');

$c->createCommand()->insert('{{%admin_users}}', [
    'username' => ADMIN,
    'password_hash' => (new NativePasswordHasher())->hash(PASSWORD),
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

$c->createCommand()->insert('{{%order58_stores}}', [
    'source_id' => STORE,
    'name' => 'Compare Alignment Fixture Store',
    'active' => 1,
    'host' => 'kf-e2e-compare.order58.com',
    'sync_hash' => str_repeat('0', 64),
    'synced_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

$c->createCommand()->insert('{{%knowledge_bases}}', [
    'name' => 'Compare Alignment Fixture Store',
    'slug' => SLUG,
    'source_system' => 'order58',
    'source_store_id' => STORE,
    'source_name' => 'Compare Alignment Fixture Store',
    'source_active' => 1,
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

/**
 * One line of an order, in the provider's own shape.
 *
 * @param list<array{string, string}> $extras   additional_name / option_name pairs
 * @param list<array{string, string}> $combos   attribute_name / option_name pairs
 *
 * @return array<string, mixed>
 */
function line(
    int $id,
    string $name,
    string $sn,
    string $price,
    array $extras = [],
    array $combos = [],
    string $deleted = '0',
    ?string $note = null,
): array {
    return [
        'id' => $id,
        'qty' => '1',
        'name' => '[' . $sn . ']' . $name,
        'price' => $price,
        'desc' => $note,
        'deleted' => $deleted,
        'order_id' => ORDER,
        'product_id' => $id + 400000,
        'data' => [
            'product' => ['id' => $id + 400000, 'sn' => $sn, 'name' => $name],
            'additionals' => array_map(
                static fn(array $e): array => [
                    'additional_name' => $e[0],
                    'option_name' => $e[1],
                    'qty' => '1',
                    'price' => '0.50000',
                ],
                $extras,
            ),
            'combinations' => $combos === [] ? null : [
                'options' => array_map(
                    static fn(array $k): array => [
                        'attribute_name' => $k[0],
                        'option_name' => $k[1],
                        'price' => '0.00000',
                    ],
                    $combos,
                ),
            ],
        ],
    ];
}

// 1 — the original wraps and the demo does not. 2 — the other way round. 3 — original only.
// 4 — removed by the provider. The demo adds a fifth line of its own, which nothing opposite matches.
$originalLines = [
    line(45300001, 'Fried Chicken Wings (4)', 'F7', '11.35000', [
        ['Add / Extra', 'Add broccoli'],
        ['Add / Extra', 'Extra crispy, well done, sauce on the side'],
    ], [['Combination With', 'Shrimp Fried Rice and an egg roll']]),
    line(45300002, 'General Tso Chicken', 'C12', '14.95000'),
    line(45300003, 'Wonton Soup', 'S3', '3.25000'),
    line(45300004, 'Egg Roll', 'A1', '1.95000', deleted: '1'),
];

$demoLines = [
    line(45300001, 'Fried Chicken Wings (4)', 'F7', '7.15000', [], [['Combination With', 'Plain (no rice)']]),
    line(45300002, 'General Tso Chicken', 'C12', '14.95000', [
        ['Add / Extra', 'Extra spicy please, chilli oil on the side'],
        ['Add / Extra', 'No peanuts — allergy'],
        ['Add / Extra', 'Steamed rice instead of fried'],
    ], [['Combination With', 'Pork Lo Mein']]),
    line(45300005, 'Crab Rangoon (6)', 'A9', '6.50000'),
];

// The billing summary's whole policy, in one order:
//   subtotal / tax      differ            -> shown, with the difference
//   shipping_fee        0.00 vs 2.50      -> shown; a zero is an answer, not a missing value
//   tip                 0.00 on both      -> suppressed, symmetrically, as an agreed-zero charge
//   surcharge/discount  absent on both    -> removed entirely
// Paid by card on both sides, so the payment rows and the processing fee are in the geometry too. The
// original kept its last four digits; the demo did not. Synthetic values, in the stored shape only — no
// card reference, expiry or code is ever stored, so none appears here.
$payload = (string) json_encode([
    'id' => (string) ORDER,
    'status' => 'completed',
    'type' => 'delivery',
    'payment_method' => 'credit card',
    'cc_processing_fee' => '0.85110',
    'card_last4' => '4242',
    'timezone' => 'America/New_York',
    'created_at' => 1783379248,
    'subtotal' => '29.55000',
    'tax' => '1.95000',
    'shipping_fee' => '0.00000',
    'tip' => '0.00000',
    'total_amount' => '31.50000',
    'data' => (string) json_encode([
        'reservation' => [
            'phone' => PHONE,
            'first_name' => 'Marta',
            'last_name' => 'Nowak',
            'customer_count' => 2,
            'instructions' => 'Ring the bell twice, leave at the side door, do not knock after 9pm.',
        ],
    ]),
]);

$c->createCommand()->insert('{{%order58_orders}}', [
    'account_id' => STORE,
    'source_order_id' => ORDER,
    'reservation_phone' => PHONE,
    'type' => 'delivery',
    'payment_method' => 'credit card',
    'status' => 'completed',
    'total_amount' => '31.50000',
    'payload_json' => $payload,
    'content_hash' => hash('sha256', $payload),
    'synced_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
    'items_status' => 'SYNCED',
    'items_synced_at' => $now,
])->execute();

$canonicalizer = new ItemCanonicalizer();

foreach ($originalLines as $position => $raw) {
    $c->createCommand()->insert('{{%order58_order_items}}', [
        'store_account_id' => STORE,
        'source_order_id' => ORDER,
        'source_item_id' => $raw['id'],
        'name' => (string) $raw['name'],
        'qty' => (string) $raw['qty'],
        'price' => $raw['price'],
        'product_id' => $raw['product_id'],
        'deleted' => (int) $raw['deleted'],
        'position' => $position,
        'comparison_json' => (string) json_encode($canonicalizer->canonicalize($raw)->toArray()),
        'content_hash' => hash('sha256', (string) json_encode($raw)),
        'created_at' => $now,
        'updated_at' => $now,
    ])->execute();
}

// A demo order that carries a payload is read from the payload, not from its columns, so the totals
// have to be stated here too or the demo side of the billing summary reads as entirely missing.
$demoPayload = (string) json_encode([
    'id' => DEMO_ORDER,
    'status' => 'completed',
    'type' => 'delivery',
    'payment_method' => 'credit card',
    'cc_processing_fee' => '0.79000',
    'timezone' => 'America/Chicago',
    'created_at' => 1783382848,
    'subtotal' => '26.10000',
    'tax' => '1.72000',
    'shipping_fee' => '2.50000',
    'tip' => '0.00000',
    'total_amount' => '28.60000',
    'items' => $demoLines,
    'data' => ['reservation' => ['phone' => PHONE, 'first_name' => 'Marta', 'customer_count' => 2]],
]);

$c->createCommand()->insert('{{%order_testing_demo_orders}}', [
    'public_id' => bin2hex(random_bytes(16)),
    'store_source_id' => STORE,
    'source_order_id' => ORDER,
    'demo_order_id' => DEMO_ORDER,
    'customer_phone' => PHONE,
    'customer_first_name' => 'Marta',
    'order_type' => 'delivery',
    'payment_method' => 'credit card',
    'status' => 'completed',
    'subtotal' => '26.10000',
    'tax' => '1.72000',
    'shipping_fee' => '2.50000',
    'tip' => '0.00000',
    'total_amount' => '28.60000',
    'address' => '162 N Main St',
    'city' => 'Freeport',
    'source_filename' => ORDER . '-' . DEMO_ORDER . '.json',
    'content_hash' => hash('sha256', $demoPayload),
    'raw_payload' => $demoPayload,
    'imported_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
])->execute();

echo json_encode([
    'admin' => ADMIN,
    'password' => PASSWORD,
    'store' => STORE,
    'order' => ORDER,
    'demo' => DEMO_ORDER,
    'url' => '/order-testing/store/' . STORE . '/order/' . ORDER . '/demo/' . DEMO_ORDER,
], JSON_PRETTY_PRINT), "\n";
