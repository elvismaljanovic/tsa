<?php
// Fajl: /var/www/html/my_sent_offers.php
// Ova datoteka radi kao fragment za content_router.php
// Sva potrebna stilizacija se učitava iz globalnih CSS fajlova preko user_header.php

session_start();
require_once __DIR__ . '/include/init.php'; // Osigurajte da je putanja ispravna

// Provjera da li je korisnik ulogovan
if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id']) || !isset($_SESSION['employee_id'])) {
    $_SESSION['error_message'] = $lang['access_denied'] ?? 'Nemate pristup ovoj stranici. Molimo prijavite se.';
    // Ako nije direktan pristup, ne radimo header redirect jer bi prekinuo dashboard
    echo '<div class="message error-message">' . htmlspecialchars($_SESSION['error_message']) . '</div>';
    unset($_SESSION['error_message']); // Očisti poruku nakon prikaza
    $sent_offers = []; // Praznimo listu da se ne bi prikazivalo ništa
    $offering_company_id = null; // Sprečava SQL upit
    $current_employee_id = null; // Također postavite na null
} else {
    $offering_company_id = $_SESSION['company_id'];
    $current_employee_id = $_SESSION['employee_id']; // Dohvatite ID trenutnog zaposlenika
}

$ad_id_filter = null;
// Provera da li je ad_id prisutan i da li je numerička vrednost
if (isset($_GET['ad_id']) && is_numeric($_GET['ad_id'])) {
    $ad_id_filter = (int)$_GET['ad_id']; // Konvertujte u integer radi sigurnosti
}

$sent_offers = [];

// Dozvoljena polja za sortiranje
$allowed_sort_fields = [
    'offer_id' => 'ao.offer_id',
    'ad_id' => 'ao.ad_id',
    'ad_owner_company_name' => 'c_ad_owner.company_name',
    'ad_type' => 'a.ad_type',
    'loading_addresses_summary' => 'loading_addresses_summary',
    'unloading_addresses_summary' => 'unloading_addresses_summary',
    'price' => 'ao.price',
    'offer_status' => 'ao.offer_status',
    'sent_at' => 'ao.created_at'
];

// Sort parametri iz URL-a
$sort_by = $_GET['sort_by'] ?? 'sent_at';
$order = strtolower($_GET['order'] ?? 'desc');

if (!array_key_exists($sort_by, $allowed_sort_fields)) {
    $sort_by = 'sent_at';
}
if (!in_array($order, ['asc', 'desc'])) {
    $order = 'desc';
}

$sql_sort_field = $allowed_sort_fields[$sort_by];

// Dohvati ponude samo ako je korisnik ulogovan ($offering_company_id nije null)
if ($offering_company_id !== null && $current_employee_id !== null) { // Dodana provjera za $current_employee_id
    try {
        $sql_select = "
            SELECT
                ao.offer_id,
                ao.ad_id,
                ao.price,
                ao.currency,
                ao.offer_status,
                DATE_FORMAT(ao.created_at, '%d.%m.%Y %H:%i') AS offer_created_at_formatted,
                ao.offering_employee_id, -- PRAVA KOLONA: ID zaposlenika koji je poslao ponudu
                a.ad_type,
                a.contact_person_id AS ad_owner_employee_id, -- ID kontakt osobe za oglas
                c_ad_owner.company_name AS ad_owner_company_name,
                e_ad_owner.first_name AS ad_owner_first_name,
                e_ad_owner.last_name AS ad_owner_last_name,
                e_ad_owner.email AS ad_owner_email,
                GROUP_CONCAT(DISTINCT CONCAT(la.address, ', ', cl.name, ', ', col.name) ORDER BY la.address SEPARATOR '; ') AS loading_addresses_summary,
                GROUP_CONCAT(DISTINCT CONCAT(ua.address, ', ', cu.name, ', ', cou.name) ORDER BY ua.address SEPARATOR '; ') AS unloading_addresses_summary,
                conv.id AS conversation_id -- ID konverzacije za opšti chat
        ";

        $sql_from_joins = "
            FROM ad_offers ao
            JOIN ads a ON ao.ad_id = a.ad_id
            JOIN employees e_ad_owner ON a.contact_person_id = e_ad_owner.id -- Direktno spajanje s employees preko contact_person_id
            LEFT JOIN companies c_ad_owner ON e_ad_owner.company_id = c_ad_owner.id
            LEFT JOIN loading_addresses la ON a.ad_id = la.ad_id
            LEFT JOIN cities cl ON la.city = cl.id
            LEFT JOIN countries col ON la.country = col.id
            LEFT JOIN unloading_addresses ua ON a.ad_id = ua.ad_id
            LEFT JOIN cities cu ON ua.city = cu.id
            LEFT JOIN countries cou ON ua.country = cou.id
            LEFT JOIN conversations conv ON (
                (conv.participant1_employee_id = ao.offering_employee_id AND conv.participant2_employee_id = a.contact_person_id)
                OR
                (conv.participant1_employee_id = a.contact_person_id AND conv.participant2_employee_id = ao.offering_employee_id)
            ) AND conv.type = 'chat' -- Dohvati SAMO opšti chat (DM), ne 'ad_offer' konverzaciju
        ";

        // Inicijalizacija WHERE uvjeta i parametara
        // Filtriranje po offering_employee_id umjesto offering_company_id
        $sql_where_parts = ["ao.offering_employee_id = :current_employee_id"];
        $params = [':current_employee_id' => $current_employee_id]; // Koristimo ID zaposlenika koji je poslao ponudu

        // DODAJEMO FILTRIRANJE PO AD_ID AKO JE PRISUTAN
        if ($ad_id_filter !== null) {
            $sql_where_parts[] = "ao.ad_id = :ad_id_filter"; // Dodajemo uvjet
            $params[':ad_id_filter'] = $ad_id_filter; // Dodajemo parametar

            // KADA FILTRIRAMO PO OGLASU, POSLEDNJA POSLANA PONUDA JE UVEK NA VRHU
            $sql_sort_field = 'ao.created_at';
            $order = 'desc';
        }

        $sql_where = " WHERE " . implode(" AND ", $sql_where_parts);

        // Ažurirana GROUP BY klauzula
        $sql_group_by = "
            GROUP BY
                ao.offer_id,
                ao.ad_id,
                ao.price,
                ao.currency,
                ao.offer_status,
                ao.created_at,
                ao.offering_employee_id,
                a.ad_type,
                a.contact_person_id,
                c_ad_owner.company_name,
                e_ad_owner.first_name,
                e_ad_owner.last_name,
                e_ad_owner.email,
                conv.id
        ";

        $sql_order_by = " ORDER BY $sql_sort_field $order";

        // Spajanje svih dijelova upita
        $sql = $sql_select . $sql_from_joins . $sql_where . $sql_group_by . $sql_order_by;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params); // Izvršavanje upita sa svim prikupljenim parametrima
        $sent_offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Greška pri dohvatanju poslatih ponuda: " . $e->getMessage());
        $_SESSION['error_message'] = $lang['db_error'] ?? 'Greška pri dohvatanju podataka.';
    }
}

// Mape za prevod
$ad_types = [
    'Utovar' => $lang['ad_type_utovar'] ?? 'Utovar',
    'Prevoz' => $lang['ad_type_prevoz'] ?? 'Prevoz',
    'Oba' => $lang['ad_type_oba'] ?? 'Utovar i Prevoz'
];
$offer_statuses = [
    'Pending' => $lang['pending'] ?? 'Na čekanju',
    'Accepted' => $lang['accepted'] ?? 'Prihvaćeno',
    'Rejected' => $lang['rejected'] ?? 'Odbijeno'
];

// Ostatak HTML strukture se sada nalazi u content_router.php / dashboard.php
// koji uključuje user_header.php, sidebar_template.php i ostale
?>

<div class="dashboard-container">
    <h1><?= htmlspecialchars($lang['my_sent_offers_heading'] ?? 'Moje poslate ponude'); ?></h1>

    <?php if (isset($_SESSION['error_message'])): ?>
        <p class="message error-message"><?= htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?></p>
    <?php endif; ?>

    <?php if (empty($sent_offers)): ?>
        <div class="no-offers-message">
            <p><?= htmlspecialchars($lang['no_sent_offers'] ?? 'Još niste poslali nijednu ponudu.'); ?></p>
            <p><a href="dashboard.php?page=list_ads"><?= htmlspecialchars($lang['browse_ads'] ?? 'Pregledajte oglase'); ?></a></p>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <?php
                    $columns = [
                        'offer_id' => ($lang['offer_id'] ?? 'ID ponude'),
                        'ad_id' => ($lang['ad_id'] ?? 'ID oglasa'),
                        'ad_owner_company_name' => ($lang['company'] ?? 'Kompanija'),
                        'ad_type' => ($lang['ad_type'] ?? 'Tip oglasa'),
                        'loading_addresses_summary' => ($lang['loading_place'] ?? 'Mjesto utovara'),
                        'unloading_addresses_summary' => ($lang['unloading_place'] ?? 'Mjesto istovara'),
                        'price' => ($lang['your_price'] ?? 'Vaša cijena'),
                        'offer_status' => ($lang['offer_status'] ?? 'Status ponude'),
                        'sent_at' => ($lang['sent_at'] ?? 'Poslano'),
                    ];
                    foreach ($columns as $key => $label):
                        $isSorted = ($sort_by === $key);
                        $nextOrder = ($isSorted && $order === 'asc') ? 'desc' : 'asc';
                        $arrow = $isSorted ? ($order === 'asc' ? ' &blacktriangle;' : ' &blacktriangledown;') : ''; // HTML strelice
                    ?>
                        <th><a href="?page=my_sent_offers&sort_by=<?= $key ?>&order=<?= $nextOrder ?>"><?= htmlspecialchars($label) . $arrow ?></a></th>
                    <?php endforeach; ?>
                    <th><?= htmlspecialchars($lang['actions'] ?? 'Akcije'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sent_offers as $offer): ?>
                    <tr>
                        <td><?= htmlspecialchars($offer['offer_id']) ?></td>
                        <td><a href="dashboard.php?page=view_ad&ad_id=<?= htmlspecialchars($offer['ad_id']) ?>"><?= htmlspecialchars($offer['ad_id']) ?></a></td>
                        <td>
                            <?= htmlspecialchars($offer['ad_owner_company_name']) ?><br>
                            <?= htmlspecialchars($offer['ad_owner_first_name'] . ' ' . $offer['ad_owner_last_name']) ?><br>
                            <a href="mailto:<?= htmlspecialchars($offer['ad_owner_email']) ?>"><?= htmlspecialchars($offer['ad_owner_email']) ?></a>
                        </td>
                        <td><?= htmlspecialchars($ad_types[$offer['ad_type']] ?? $offer['ad_type']) ?></td>
                        <td><?= htmlspecialchars($offer['loading_addresses_summary']) ?></td>
                        <td><?= htmlspecialchars($offer['unloading_addresses_summary'] ?? '') ?></td>
                        <td><?= htmlspecialchars(number_format($offer['price'], 2, ',', '.')) . ' ' . htmlspecialchars($offer['currency']) ?></td>
                        <td class="status-<?= strtolower($offer['offer_status']) ?>">
                            <?= htmlspecialchars($offer_statuses[$offer['offer_status']] ?? $offer['offer_status']) ?>
                        </td>
                        <td><?= htmlspecialchars($offer['offer_created_at_formatted']) ?></td>
 <td>
                            <?php
                            $chat_partner_id = $offer['ad_owner_employee_id']; // Vlasnik oglasa je partner
                            $chat_partner_name = htmlspecialchars($offer['ad_owner_first_name'] . ' ' . $offer['ad_owner_last_name']);
                            $chat_conversation_id = $offer['conversation_id']; // ID opšteg chata
                            $current_logged_in_employee_id = $_SESSION['employee_id']; // Vaš ID

                            // Provjera da li su oba participant ID-a validna (da nisu 0, null ili slično)
                            $can_chat = ($chat_partner_id && $current_logged_in_employee_id && $chat_conversation_id !== null);
                            ?>
                            <button class="btn btn-info open-dm-chat-btn"
                                    data-conversation-id="<?= htmlspecialchars($chat_conversation_id); ?>"
                                    data-partner-id="<?= htmlspecialchars($chat_partner_id); ?>"
                                    data-current-employee-id="<?= htmlspecialchars($current_logged_in_employee_id); ?>"
                                    data-partner-name="<?= htmlspecialchars($chat_partner_name); ?>"
                                    <?= ($can_chat) ? '' : 'disabled'; ?>
                                    title="<?= ($can_chat) ? ($lang['chat_with_owner'] ?? 'Chat s vlasnikom oglasa') : ($lang['chat_unavailable'] ?? 'Chat nije dostupan'); ?>"
                                    >
                                <?= htmlspecialchars($lang['chat_with_owner'] ?? 'Chat s vlasnikom oglasa'); ?>
                            </button>
                            </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>