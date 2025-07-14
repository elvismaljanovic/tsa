<?php
// Fajl: /home/tsaba/app.tsa.ba/view_ad.php
// Ova datoteka radi kao samostalna stranica ILI kao fragment za content_router.php

// Uvek session_start() i init.php moraju biti na samom vrhu, bez ikakvog izlaza pre toga!
session_start();
require_once __DIR__ . '/include/init.php';

$is_direct_access = (__FILE__ == $_SERVER['SCRIPT_FILENAME']);

$success_message = '';
$important_message = '';
$error_message = '';

// Dohvati i obriši poruke iz sesije
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['important_message'])) {
    $important_message = $_SESSION['important_message'];
    unset($_SESSION['important_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// 1. Provjera ad_id
if (!isset($_GET['ad_id']) || !is_numeric($_GET['ad_id'])) {
    $_SESSION['error_message'] = $lang['invalid_ad_id'] ?? 'Nevažeći ID oglasa.';
    $ad_id = null; // Označi da je ID nevažeći
} else {
    $ad_id = (int)$_GET['ad_id'];
}

// Provjeri da li je korisnik ulogovan i uzmi podatke iz sesije
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_message'] = $lang['access_denied'] ?? 'Nemate pristup ovoj stranici.';
    $ad_id = null; // Onemogući daljnje dohvaćanje podataka
} else {
    $user_role = $_SESSION['user_role'] ?? '';
    $user_company_id = $_SESSION['company_id'] ?? 0;
}

// Dohvati podatke samo ako je ad_id validan i korisnik ulogovan
if ($ad_id !== null) {
    try {
        // Dohvati samo ključne podatke o oglasu za proveru pristupa
        $stmt = $pdo->prepare("SELECT company_id, ad_type, offer_status FROM ads WHERE ad_id = :ad_id");
        $stmt->execute([':ad_id' => $ad_id]);
        $ad_for_access_check = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ad_for_access_check) {
            $_SESSION['error_message'] = $lang['ad_not_found'] ?? 'Oglas nije pronađen.';
            $ad_id = null; // Onemogući daljnje dohvaćanje podataka
        } else {
            // Proveri da li je korisnik vlasnik oglasa
            $is_owner = ($ad_for_access_check['company_id'] == $user_company_id);

            // Ako nije vlasnik, primeni specifičnu logiku pristupa na osnovu uloge i statusa/tipa oglasa
            if (!$is_owner) {
                $can_access = false; // Postavi pretpostavku da korisnik ne može pristupiti
                $ad_offer_status = $ad_for_access_check['offer_status'];
                $ad_type = $ad_for_access_check['ad_type'];

                // Svi tuđi oglasi moraju biti 'Open'
                if ($ad_offer_status === 'Open') {
                    switch ($user_role) {
                        case 'client':
                            // Client vidi tuđe oglase samo ako je ad_type = 'Prevoz'
                            if ($ad_type === 'Prevoz') {
                                $can_access = true;
                            }
                            break;
                        case 'carrier':
                            // Carrier vidi tuđe oglase samo ako je ad_type = 'Utovar'
                            if ($ad_type === 'Utovar') {
                                $can_access = true;
                            }
                            break;
                        case 'forwarder':
                            // Forwarder vidi sve tuđe otvorene oglase (bez obzira na ad_type)
                            $can_access = true;
                            break;
                        default:
                            // Sve ostale uloge ne mogu videti tuđe oglase
                            $can_access = false;
                            break;
                    }
                }

                if (!$can_access) {
                    $_SESSION['error_message'] = $lang['access_denied'] ?? 'Nemate pristup ovom oglasu.';
                    $ad_id = null; // Onemogući daljnje dohvaćanje podataka
                }
            }
        }

        // Nastavi s dohvaćanjem svih detalja ako je pristup dozvoljen
        if ($ad_id !== null) {
            // Glavni SQL upit za dohvaćanje detalja oglasa
            $stmt = $pdo->prepare("SELECT
                ads.ad_id,
                c.id AS company_id,
                c.company_name,
                e.id AS employee_id,
                e.first_name AS employee_first_name,
                e.last_name AS employee_last_name,
                ads.ad_type,
                DATE_FORMAT(ads.created_at, '%d.%m.%Y %H:%i') AS ad_created_at_formatted,
                DATE_FORMAT(ads.updated_at, '%d.%m.%Y %H:%i') AS ad_updated_at_formatted,
                ads.offer_status AS ad_offer_status,
                DATE_FORMAT(ads.loading_date_from, '%d.%m.%Y') AS loading_date_from_formatted,
                DATE_FORMAT(ads.loading_date_to, '%d.%m.%Y') AS loading_date_to_formatted,
                DATE_FORMAT(ads.unloading_date_from, '%d.%m.%Y') AS unloading_date_from_formatted,
                DATE_FORMAT(ads.unloading_date_to, '%d.%m.%Y') AS unloading_date_to_formatted,
                cd.cargo_insurance,
                cd.gps,
                cd.interchangeable_trailer,
                cd.certificates,
                cd.trips_per_day,
                cd.round_trip,
                cd.transport_start_on_days,
                cd.loading_equipment,
                cd.more_information,
                cd.package_exchange,
                cd.possible_stacking,
                cd.cargo_exchange,
                cd.hazmat,
                cd.notes,
                cd.internal_notes,
                p.price_currency,
                p.requested_price,
                p.payment_method,
                DATE_FORMAT(p.payment_due_date, '%d.%m.%Y') AS payment_due_date_formatted,
                p.value_of_goods,
                p.accounting_type,
                p.accounting_cycle,
                p.offer_status AS price_offer_status,
                vd.vehicle_type,
                vd.trailer_type,
                vd.trailer_properties,
                vd.vehicle_equipment
            FROM ads
            LEFT JOIN companies c ON ads.company_id = c.id
            LEFT JOIN employees e ON ads.contact_person_id = e.id
            LEFT JOIN cargo_details cd ON ads.ad_id = cd.ad_id
            LEFT JOIN pricing p ON ads.ad_id = p.ad_id
            LEFT JOIN vehicle_details vd ON ads.ad_id = vd.ad_id
            WHERE ads.ad_id = ?");

            $stmt->execute([$ad_id]);
            $ad = $stmt->fetch(PDO::FETCH_ASSOC);

            // 2. Provjera da li je oglas pronađen (ako nije, $ad_id bi već bio null)
            if (!$ad) {
                $_SESSION['error_message'] = $lang['ad_not_found'] ?? 'Oglas nije pronađen.';
                $ad_id = null;
            }

            // Dohvati ostale podatke samo ako je glavni oglas pronađen i validan
            if ($ad_id !== null) {
                // Dohvati podatke o teretu (freight)
                $freightStmt = $pdo->prepare("SELECT type_of_goods, load_weight, load_width, load_height, load_length, cargo_volume FROM freight WHERE ad_id = ?");
                $freightStmt->execute([$ad_id]);
                $freights = $freightStmt->fetchAll(PDO::FETCH_ASSOC);

                // Dohvati dokumente
                $docStmt = $pdo->prepare("SELECT document_id, document_name, document_type, document_file, created_at, updated_at FROM documents WHERE ad_id = ?");
                $docStmt->execute([$ad_id]);
                $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);

                // Dohvati adrese utovara
                $loadStmt = $pdo->prepare("SELECT la.address AS loading_address, c.name AS loading_city_name, c.postal_code AS loading_postal_code, co.name AS loading_country_name
                    FROM loading_addresses la
                    LEFT JOIN cities c ON la.city = c.id
                    LEFT JOIN countries co ON la.country = co.id
                    WHERE la.ad_id = ?");
                $loadStmt->execute([$ad_id]);
                $loading_addresses = $loadStmt->fetchAll(PDO::FETCH_ASSOC);

                // Dohvati adrese istovara
                $unloadStmt = $pdo->prepare("SELECT ua.address AS unloading_address, c.name AS unloading_city_name, c.postal_code AS unloading_postal_code, co.name AS unloading_country_name
                    FROM unloading_addresses ua
                    LEFT JOIN cities c ON ua.city = c.id
                    LEFT JOIN countries co ON ua.country = co.id
                    WHERE ua.ad_id = ?");
                $unloadStmt->execute([$ad_id]);
                $unloading_addresses = $unloadStmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (PDOException $e) {
        error_log("Greška pri dohvatanju detalja oglasa (ad_id: $ad_id): " . $e->getMessage());
        $_SESSION['error_message'] = $lang['db_error'] ?? 'Došlo je do greške pri dohvatanju podataka.';
        $ad_id = null; // Onemogući prikaz
    }
}

// Mape za prevode
$ad_types = [
    'Utovar' => $lang['ad_type_utovar'] ?? 'Utovar',
    'Prevoz' => $lang['ad_type_prevoz'] ?? 'Prevoz',
    'Oba' => $lang['ad_type_oba'] ?? 'Utovar i Prevoz'
];
$offer_statuses = [
    'Pending' => $lang['pending'] ?? 'Na čekanju',
    'In Progress' => $lang['in_progress'] ?? 'U toku',
    'Open' => $lang['open'] ?? 'Otvoreno',
    'Closed' => $lang['closed'] ?? 'Zatvoreno',
    'Accepted' => $lang['accepted'] ?? 'Prihvaćeno',
    'Rejected' => $lang['rejected'] ?? 'Odbijeno'
];
$boolean_status = [
    '1' => $lang['yes'] ?? 'Da',
    '0' => $lang['no'] ?? 'Ne'
];
$payment_methods = [
    'Bank Transfer' => $lang['payment_method_bank_transfer'] ?? 'Bankovni transfer',
    'Cash' => $lang['payment_method_cash'] ?? 'Gotovina',
    // Dodajte ostale načine plaćanja koje imate u bazi
];
$accounting_types = [
    'Invoice' => $lang['accounting_type_invoice'] ?? 'Faktura',
    // Dodajte ostale tipove obračuna
];
$accounting_cycles = [
    'Monthly' => $lang['accounting_cycle_monthly'] ?? 'Mesečno',
    'Weekly' => $lang['accounting_cycle_weekly'] ?? 'Nedeljno',
    // Dodajte ostale cikluse obračuna
];
$vehicle_types_map = [
    'Truck' => $lang['truck'] ?? 'Kamion',
    'Van' => $lang['van'] ?? 'Kombi',
    'Container' => $lang['container'] ?? 'Kontejner',
    // Dodajte ostale tipove vozila
];
$goods_types = [
    'Electronics' => $lang['electronics'] ?? 'Elektronika',
    'Furniture' => $lang['furniture'] ?? 'Namještaj',
    'Food' => $lang['food'] ?? 'Hrana',
    // Dodajte ostale vrste robe
];

// --- KOD ZA PRISTIGLE PONUDE ---
$received_offers = [];
// Dohvati ponude samo ako je korisnik vlasnik oglasa i oglas je validan
if ($ad_id !== null && $is_owner) { 
    try {
        $stmt_offers = $pdo->prepare("
            SELECT
                ao.offer_id,
                ao.price,
                ao.currency,
                ao.offer_status,
                DATE_FORMAT(ao.created_at, '%d.%m.%Y %H:%i') AS offer_created_at_formatted,
                c.company_name AS offering_company_name,
                e.first_name AS offering_first_name,
                e.last_name AS offering_last_name
            FROM
                ad_offers ao
            JOIN
                companies c ON ao.offering_company_id = c.id
            LEFT JOIN
                employees e ON c.id = e.company_id AND e.position = 'Contact Person' -- ILI SLIČNO, AKO IMATE SPECIFIČNU POZICIJU ZA KONTAKT OSOBU
            WHERE
                ao.ad_id = :ad_id
            ORDER BY
                ao.created_at DESC
        ");
        $stmt_offers->execute([':ad_id' => $ad_id]);
        $received_offers = $stmt_offers->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Greška pri dohvatanju primljenih ponuda za oglas (ad_id: $ad_id): " . $e->getMessage());
        $_SESSION['error_message'] = $lang['db_error_offers'] ?? 'Došlo je do greške pri dohvatanju pristiglih ponuda.';
    }
}
// --- KRAJ KODA ZA PRISTIGLE PONUDE ---

// Logika za prikaz cijele stranice (ako se pristupa direktno) ili samo sadržaja (ako je uključeno)
if ($is_direct_access) {
    // Ovo se izvršava samo ako se view_ad.php direktno poziva
    require_once __DIR__ . '/include/user_header.php'; // Otvara html, head, body
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang['current_lang'] ?? 'bs'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($lang['ad_details_title'] ?? 'Detalji oglasa'); ?>: <?= htmlspecialchars($ad['ad_id'] ?? ''); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="/css/dashboard.css" /> 
    <link rel="stylesheet" href="/css/view.css" />
</head>
<body>

<?php require_once __DIR__ . '/include/greybar_template.php'; ?>
<?php require_once __DIR__ . '/include/bluebar_template.php'; ?>

<div class="app-container">
    <?php require_once __DIR__ . '/include/sidebar_template.php'; ?>

    <div class="main-content-wrapper">
        <div class="dynamic-content">
<?php
} // Kraj if ($is_direct_access)
?>

<div class="dashboard-container">
    <?php if (!empty($success_message)): ?>
        <div class="message success-message">
            <?= htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($important_message)): ?>
        <div class="message important-message">
            <?= htmlspecialchars($important_message); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="message error-message">
            <?= htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <?php if ($ad_id === null || !$ad): // Prikaz poruke ako oglas nije pronađen ili pristup odbijen ?>
        <p class="error-message"><?= htmlspecialchars($_SESSION['error_message'] ?? ($lang['general_error'] ?? 'Došlo je do greške ili oglas nije pronađen.')); unset($_SESSION['error_message']); ?></p>
    <?php else: // Prikaži detalje oglasa samo ako je sve u redu ?>

        <h1><?= htmlspecialchars($lang['ad_details_title'] ?? 'Detalji oglasa'); ?>: <?= htmlspecialchars($ad['ad_id'] ?? ''); ?></h1>

        <div class="ad-details-section">
            <h2><?= htmlspecialchars($lang['general_information'] ?? 'Opšte informacije'); ?></h2>
            <ul>
                <li><strong><?= htmlspecialchars($lang['ad_id'] ?? 'ID oglasa'); ?>:</strong> <?= htmlspecialchars($ad['ad_id'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['company'] ?? 'Kompanija'); ?>:</strong> <a href="dashboard.php?page=view_companies&company_id=<?= htmlspecialchars($ad['company_id'] ?? '') ?>"><?= htmlspecialchars($ad['company_name'] ?? '') ?></a></li>
                <li><strong><?= htmlspecialchars($lang['contact_person'] ?? 'Kontakt osoba'); ?>:</strong> <a href="dashboard.php?page=employee_details&employee_id=<?= htmlspecialchars($ad['employee_id'] ?? '') ?>"><?= htmlspecialchars($ad['employee_first_name'] ?? '') ?> <?= htmlspecialchars($ad['employee_last_name'] ?? '') ?></a></li>
                <li><strong><?= htmlspecialchars($lang['ad_type'] ?? 'Tip oglasa'); ?>:</strong> <?= htmlspecialchars($ad_types[$ad['ad_type']] ?? $ad['ad_type'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['offer_status'] ?? 'Status oglasa'); ?>:</strong> <?= htmlspecialchars($offer_statuses[$ad['ad_offer_status']] ?? $ad['ad_offer_status'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['created_at'] ?? 'Kreirano'); ?>:</strong> <?= htmlspecialchars($ad['ad_created_at_formatted'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['updated_at'] ?? 'Ažurirano'); ?>:</strong> <?= htmlspecialchars($ad['ad_updated_at_formatted'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['loading_date_from'] ?? 'Datum utovara od'); ?>:</strong> <?= htmlspecialchars($ad['loading_date_from_formatted'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['loading_date_to'] ?? 'Datum utovara do'); ?>:</strong> <?= htmlspecialchars($ad['loading_date_to_formatted'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['unloading_date_from'] ?? 'Datum istovara od'); ?>:</strong> <?= htmlspecialchars($ad['unloading_date_from_formatted'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['unloading_date_to'] ?? 'Datum istovara do'); ?>:</strong> <?= htmlspecialchars($ad['unloading_date_to_formatted'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['notes'] ?? 'Napomene'); ?>:</strong> <?= htmlspecialchars($ad['notes'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['internal_notes'] ?? 'Interne napomene'); ?>:</strong> <?= htmlspecialchars($ad['internal_notes'] ?? '') ?></li>
            </ul>
        </div>

        <div class="ad-details-section">
            <h2><?= htmlspecialchars($lang['loading_places'] ?? 'Mjesta utovara'); ?></h2>
            <?php if (!empty($loading_addresses)): ?>
                <?php foreach ($loading_addresses as $i => $la): ?>
                    <div class="address-block">
                        <h3><?= htmlspecialchars($lang['loading_place'] ?? 'Mjesto utovara'); ?> <?= $i + 1 ?></h3>
                        <ul>
                            <li><strong><?= htmlspecialchars($lang['address'] ?? 'Adresa'); ?>:</strong> <?= htmlspecialchars($la['loading_address'] ?? '') ?></li>
                            <li><strong><?= htmlspecialchars($lang['city'] ?? 'Grad'); ?>:</strong> <?= htmlspecialchars($la['loading_city_name'] ?? '') ?> (<?= htmlspecialchars($la['loading_postal_code'] ?? '') ?>)</li>
                            <li><strong><?= htmlspecialchars($lang['country'] ?? 'Država'); ?>:</strong> <?= htmlspecialchars($la['loading_country_name'] ?? '') ?></li>
                        </ul>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p><?= htmlspecialchars($lang['no_loading_places'] ?? 'Nema definisanih mjesta utovara.'); ?></p>
            <?php endif; ?>
        </div>

        <div class="ad-details-section">
            <h2><?= htmlspecialchars($lang['unloading_places'] ?? 'Mjesta istovara'); ?></h2>
            <?php if (!empty($unloading_addresses)): ?>
                <?php foreach ($unloading_addresses as $i => $ua): ?>
                    <div class="address-block">
                        <h3><?= htmlspecialchars($lang['unloading_place'] ?? 'Mjesto istovara'); ?> <?= $i + 1 ?></h3>
                        <ul>
                            <li><strong><?= htmlspecialchars($lang['address'] ?? 'Adresa'); ?>:</strong> <?= htmlspecialchars($ua['unloading_address'] ?? '') ?></li>
                            <li><strong><?= htmlspecialchars($lang['city'] ?? 'Grad'); ?>:</strong> <?= htmlspecialchars($ua['unloading_city_name'] ?? '') ?> (<?= htmlspecialchars($ua['unloading_postal_code'] ?? '') ?>)</li>
                            <li><strong><?= htmlspecialchars($lang['country'] ?? 'Država'); ?>:</strong> <?= htmlspecialchars($ua['unloading_country_name'] ?? '') ?></li>
                        </ul>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p><?= htmlspecialchars($lang['no_unloading_places'] ?? 'Nema definisanih mjesta istovara.'); ?></p>
            <?php endif; ?>
        </div>

        <div class="ad-details-section">
            <h2><?= htmlspecialchars($lang['freight_details'] ?? 'Detalji tereta'); ?></h2>
            <?php if (!empty($freights)): ?>
                <?php foreach ($freights as $i => $freight): ?>
                    <div class="freight-block">
                        <h3><?= htmlspecialchars($lang['freight'] ?? 'Teret'); ?> <?= $i + 1 ?></h3>
                        <ul>
                            <li><strong><?= htmlspecialchars($lang['type_of_goods'] ?? 'Vrsta robe'); ?>:</strong> <?= htmlspecialchars($goods_types[$freight['type_of_goods']] ?? $freight['type_of_goods'] ?? '') ?></li>
                            <li><strong><?= htmlspecialchars($lang['load_weight'] ?? 'Težina tereta'); ?>:</strong> <?= htmlspecialchars($freight['load_weight'] ?? '') ?> kg</li>
                            <li><strong><?= htmlspecialchars($lang['load_dimensions'] ?? 'Dimenzije (ŠxVxD)'); ?>:</strong> <?= htmlspecialchars($freight['load_width'] ?? '') ?> x <?= htmlspecialchars($freight['load_height'] ?? '') ?> x <?= htmlspecialchars($freight['load_length'] ?? '') ?> m</li>
                            <li><strong><?= htmlspecialchars($lang['load_volume'] ?? 'Zapremina'); ?>:</strong> <?= htmlspecialchars($freight['cargo_volume'] ?? '') ?> m³</li>
                            </ul>
                        </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p><?= htmlspecialchars($lang['no_freight_data'] ?? 'Nema podataka o teretu.'); ?></p>
            <?php endif; ?>
        </div>

        <div class="ad-details-section">
            <h2><?= htmlspecialchars($lang['pricing'] ?? 'Cijene'); ?></h2>
            <ul>
                <li><strong><?= htmlspecialchars($lang['requested_price'] ?? 'Tražena cena'); ?>:</strong>
                    <?php
                    if (isset($ad['requested_price']) && $ad['requested_price'] !== null) {
                        echo htmlspecialchars(number_format($ad['requested_price'], 2, ',', '.') . ' ' . ($ad['price_currency'] ?? ''));
                    } else {
                        echo htmlspecialchars($lang['not_set'] ?? 'Nije postavljeno');
                    }
                    ?>
                </li>
                <li><strong><?= htmlspecialchars($lang['payment_method'] ?? 'Način plaćanja'); ?>:</strong> <?= htmlspecialchars($payment_methods[$ad['payment_method']] ?? $ad['payment_method'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['payment_due_date'] ?? 'Rok plaćanja'); ?>:</strong> <?= htmlspecialchars($ad['payment_due_date_formatted'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['value_of_goods'] ?? 'Vrednost robe'); ?>:</strong> <?= number_format($ad['value_of_goods'] ?? 0, 2, ',', '.') ?> <?= htmlspecialchars($ad['price_currency'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['accounting_type'] ?? 'Vrsta obračuna'); ?>:</strong> <?= htmlspecialchars($accounting_types[$ad['accounting_type']] ?? $ad['accounting_type'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['accounting_cycle'] ?? 'Ciklus obračuna'); ?>:</strong> <?= htmlspecialchars($accounting_cycles[$ad['accounting_cycle']] ?? $ad['accounting_cycle'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['offer_status'] ?? 'Status ponude (cena)'); ?>:</strong> <?= htmlspecialchars($offer_statuses[$ad['price_offer_status']] ?? $ad['price_offer_status'] ?? '') ?></li>
            </ul>
        </div>

        <div class="ad-details-section">
            <h2><?= htmlspecialchars($lang['vehicle'] ?? 'Vozilo'); ?></h2>
            <ul>
                <li><strong><?= htmlspecialchars($lang['vehicle_type'] ?? 'Tip vozila'); ?>:</strong> <?= htmlspecialchars($vehicle_types_map[$ad['vehicle_type']] ?? $ad['vehicle_type'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['trailer_type'] ?? 'Tip prikolice'); ?>:</strong> <?= htmlspecialchars($ad['trailer_type'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['trailer_properties'] ?? 'Karakteristike prikolice'); ?>:</strong> <?= htmlspecialchars($ad['trailer_properties'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['vehicle_equipment'] ?? 'Oprema vozila'); ?>:</strong> <?= htmlspecialchars($ad['vehicle_equipment'] ?? '') ?></li>
            </ul>
        </div>

        <div class="ad-details-section">
            <h2><?= htmlspecialchars($lang['additional_cargo_details'] ?? 'Dodatni detalji tereta'); ?></h2>
            <ul>
                <li><strong><?= htmlspecialchars($lang['cargo_insurance'] ?? 'Osiguranje tereta'); ?>:</strong> <?= htmlspecialchars($boolean_status[$ad['cargo_insurance'] ?? 0] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['gps'] ?? 'GPS'); ?>:</strong> <?= htmlspecialchars($boolean_status[$ad['gps'] ?? 0] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['interchangeable_trailer'] ?? 'Zamjenjiva prikolica'); ?>:</strong> <?= htmlspecialchars($boolean_status[$ad['interchangeable_trailer'] ?? 0] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['certificates'] ?? 'Certifikati'); ?>:</strong> <?= htmlspecialchars($boolean_status[$ad['certificates'] ?? 0] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['trips_per_day'] ?? 'Broj putovanja dnevno'); ?>:</strong> <?= htmlspecialchars($ad['trips_per_day'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['round_trip'] ?? 'Povratno putovanje'); ?>:</strong> <?= htmlspecialchars($boolean_status[$ad['round_trip'] ?? 0] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['transport_start_on_days'] ?? 'Polazak u danima'); ?>:</strong> <?= htmlspecialchars($ad['transport_start_on_days'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['loading_equipment'] ?? 'Oprema za utovar'); ?>:</strong> <?= htmlspecialchars($ad['loading_equipment'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['more_information'] ?? 'Više informacija'); ?>:</strong> <?= htmlspecialchars($ad['more_information'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['package_exchange'] ?? 'Razmjena paketa'); ?>:</strong> <?= htmlspecialchars($boolean_status[$ad['package_exchange'] ?? 0] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['possible_stacking'] ?? 'Moguće slaganje'); ?>:</strong> <?= htmlspecialchars($boolean_status[$ad['possible_stacking'] ?? 0] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['cargo_exchange'] ?? 'Razmjena tereta'); ?>:</strong> <?= htmlspecialchars($boolean_status[$ad['cargo_exchange'] ?? 0] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['hazmat'] ?? 'Opasan teret'); ?>:</strong> <?= htmlspecialchars($boolean_status[$ad['hazmat'] ?? 0] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['notes'] ?? 'Napomene'); ?>:</strong> <?= htmlspecialchars($ad['notes'] ?? '') ?></li>
                <li><strong><?= htmlspecialchars($lang['internal_notes'] ?? 'Interne napomene'); ?>:</strong> <?= htmlspecialchars($ad['internal_notes'] ?? '') ?></li>
            </ul>
        </div>

        <div class="ad-details-section">
            <h2><?= htmlspecialchars($lang['documents'] ?? 'Dokumenti'); ?></h2>
            <?php if (!empty($documents)): ?>
                <ul>
                <?php foreach ($documents as $doc): ?>
                        <li>
                            <?= htmlspecialchars($doc['document_name'] ?? 'Nema imena dokumenta') ?> (<?= htmlspecialchars($doc['document_type'] ?? '') ?>) -
                            <?php if (!empty($doc['document_file'])): ?>
                                <a href="uploads/ads/<?= urlencode($doc['document_file']) ?>" target="_blank"><?= htmlspecialchars($lang['download'] ?? 'Preuzmi') ?></a>
                            <?php else: ?>
                                <?= htmlspecialchars($lang['no_file'] ?? 'Nema datoteke') ?>
                            <?php endif; ?>
                        </li>
                <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p><?= htmlspecialchars($lang['no_documents_found'] ?? 'Nema dokumenata.'); ?></p>
            <?php endif; ?>
        </div>

        <br>
        <p><a href="javascript:history.back()">&larr; <?= htmlspecialchars($lang['back'] ?? 'Nazad'); ?></a></p>
    <?php endif; // Kraj if ($ad_id === null || !$ad) ?>
</div>

<?php if ($ad_id !== null && $ad): // Prikazati dugmad samo ako je oglas validan ?>
    <div class="button-container">
        <?php if (!$is_owner): ?>
            <button id="sendOfferBtn" class="btn btn-primary"><?= htmlspecialchars($lang['send_offer'] ?? 'Pošalji ponudu'); ?></button>
        <?php endif; ?>
        
    </div>

    <div id="sendOfferModal" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2><?= htmlspecialchars($lang['send_offer_title'] ?? 'Pošalji ponudu'); ?></h2>
            <form id="offerForm">
                <input type="hidden" name="ad_id" value="<?= htmlspecialchars($ad['ad_id'] ?? '') ?>">
                <label for="offerPrice"><?= htmlspecialchars($lang['price'] ?? 'Cijena'); ?>:</label>
                <input type="number" id="offerPrice" name="price" step="0.01" min="0" required>
                <label for="offerCurrency"><?= htmlspecialchars($lang['currency'] ?? 'Valuta'); ?>:</label>
                <select id="offerCurrency" name="currency" required>
                    <option value="KM">KM</option>
                    <option value="EUR">EUR</option>
                </select>
                <br>
                <button type="submit" class="btn btn-primary"><?= htmlspecialchars($lang['submit_offer'] ?? 'Pošalji'); ?></button>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const sendOfferBtn = document.getElementById('sendOfferBtn');
        const sendOfferModal = document.getElementById('sendOfferModal');
        const closeButton = document.querySelector('#sendOfferModal .close-button');
        const offerForm = document.getElementById('offerForm');

        if (sendOfferBtn) {
            sendOfferBtn.addEventListener('click', function() {
                sendOfferModal.style.display = 'flex';
            });
        }

        if (closeButton) {
            closeButton.addEventListener('click', function() {
                sendOfferModal.style.display = 'none';
            });
        }

        window.addEventListener('click', function(event) {
            if (event.target == sendOfferModal) {
                sendOfferModal.style.display = 'none';
            }
        });

        if (offerForm) {
            offerForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const adId = this.querySelector('input[name="ad_id"]').value;
                const price = document.getElementById('offerPrice').value;
                const currency = document.getElementById('offerCurrency').value;

                if (price === "" || isNaN(price) || parseFloat(price) <= 0) {
                    alert('<?= htmlspecialchars($lang['invalid_price_message'] ?? 'Molimo unesite validnu cijenu.'); ?>');
                    return;
                }

                // AJAX zahtjev za slanje ponude
                // Putanja 'submit_offer.php' mora biti relativna od trenutnog foldera!
                // Ako je view_ad.php u rootu, onda je ok. Ako je u podfolderu, putanja se mijenja.
                fetch('submit_offer.php', { 
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `ad_id=${encodeURIComponent(adId)}&price=${encodeURIComponent(price)}&currency=${encodeURIComponent(currency)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        sendOfferModal.style.display = 'none';
                    } else {
                        alert('<?= htmlspecialchars($lang['error'] ?? 'Greška'); ?>: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('<?= htmlspecialchars($lang['error_sending_offer'] ?? 'Došlo je do greške prilikom slanja ponude:'); ?>', error);
                    alert('<?= htmlspecialchars($lang['general_error_sending_offer'] ?? 'Došlo je do greške prilikom slanja ponude.'); ?>');
                });
            });
        }
    });
    </script>
<?php
// Dugme se prikazuje samo ako je ad_id validan I korisnik NIJE vlasnik oglasa
if ($ad_id !== null && !$is_owner) :
?>
    <button onclick="window.location.href='dashboard.php?page=my_sent_offers&ad_id=<?= htmlspecialchars($ad_id); ?>'" class="btn btn-info"><?= htmlspecialchars($lang['view_my_offers'] ?? 'Pregled svih mojih poslanih ponuda'); ?></button>
<?php endif; ?>

    <?php if ($is_owner): // Prikazati samo ako je korisnik vlasnik oglasa ?>
        <div class="ad-details-section">
            <h2><?= htmlspecialchars($lang['received_offers'] ?? 'Pristigle ponude'); ?></h2>
            <?php if (empty($received_offers)): ?>
                <p><?= htmlspecialchars($lang['no_received_offers'] ?? 'Nema pristiglih ponuda.'); ?></p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th><?= htmlspecialchars($lang['offer_id'] ?? 'ID ponude'); ?></th>
                            <th><?= htmlspecialchars($lang['offering_company'] ?? 'Kompanija ponuđača'); ?></th>
                            <th><?= htmlspecialchars($lang['contact_person'] ?? 'Kontakt osoba'); ?></th>
                            <th><?= htmlspecialchars($lang['price'] ?? 'Cijena'); ?></th>
                            <th><?= htmlspecialchars($lang['currency'] ?? 'Valuta'); ?></th>
                            <th><?= htmlspecialchars($lang['status'] ?? 'Status'); ?></th>
                            <th><?= htmlspecialchars($lang['created_at'] ?? 'Kreirano'); ?></th>
                            <th><?= htmlspecialchars($lang['actions'] ?? 'Akcije'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($received_offers as $offer): ?>
                            <tr>
                                <td><?= htmlspecialchars($offer['offer_id'] ?? ''); ?></td>
                                <td><?= htmlspecialchars($offer['offering_company_name'] ?? ''); ?></td>
                                <td><?= htmlspecialchars($offer['offering_first_name'] ?? '') . ' ' . htmlspecialchars($offer['offering_last_name'] ?? ''); ?></td>
                                <td><?= htmlspecialchars(number_format($offer['price'], 2, ',', '.') ?? ''); ?></td>
                                <td><?= htmlspecialchars($offer['currency'] ?? ''); ?></td>
                                <td><?= htmlspecialchars($offer_statuses[$offer['offer_status']] ?? $offer['offer_status'] ?? ''); ?></td>
                                <td><?= htmlspecialchars($offer['offer_created_at_formatted'] ?? ''); ?></td>
                                <td>
                                    <?php if ($offer['offer_status'] === 'Pending'): ?>
                                        <button class="btn btn-success accept-offer-btn" data-offer-id="<?= $offer['offer_id']; ?>"><?= htmlspecialchars($lang['accept'] ?? 'Prihvati'); ?></button>
                                        <button class="btn btn-danger reject-offer-btn" data-offer-id="<?= $offer['offer_id']; ?>"><?= htmlspecialchars($lang['reject'] ?? 'Odbij'); ?></button>
                                    <?php else: ?>
                                        <?= htmlspecialchars($lang['no_actions'] ?? 'Nema akcija'); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <script>
            document.querySelectorAll('.accept-offer-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const offerId = this.dataset.offerId;
                    if (confirm('<?= htmlspecialchars($lang['confirm_accept_offer'] ?? 'Jeste li sigurni da želite prihvatiti ovu ponudu?'); ?>')) {
                        // Putanja 'process_offer.php' mora biti relativna od trenutnog foldera!
                        fetch('process_offer.php', { 
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `offer_id=${offerId}&action=accept`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert(data.message);
                                window.location.reload(); 
                            } else {
                                alert('<?= htmlspecialchars($lang['error'] ?? 'Greška'); ?>: ' + data.message);
                            }
                        })
                        .catch(error => console.error('<?= htmlspecialchars($lang['error_processing_offer'] ?? 'Greška pri obradi ponude:'); ?>', error));
                    }
                });
            });

            document.querySelectorAll('.reject-offer-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const offerId = this.dataset.offerId;
                    if (confirm('<?= htmlspecialchars($lang['confirm_reject_offer'] ?? 'Jeste li sigurni da želite odbiti ovu ponudu?'); ?>')) {
                        // Putanja 'process_offer.php' mora biti relativna od trenutnog foldera!
                        fetch('process_offer.php', { 
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `offer_id=${offerId}&action=reject`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                alert(data.message);
                                window.location.reload(); 
                            } else {
                                alert('<?= htmlspecialchars($lang['error'] ?? 'Greška'); ?>: ' + data.message);
                            }
                        })
                        .catch(error => console.error('<?= htmlspecialchars($lang['error_processing_offer'] ?? 'Greška pri obradi ponude:'); ?>', error));
                    }
                });
            });
        </script>
    <?php endif; ?>
<?php endif; ?>

<?php
if ($is_direct_access) {
    // Ovo se izvršava samo ako se view_ad.php direktno poziva
?>
        </div> </div> </div> <script src="public/js/global.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php include 'include/guest_footer.php'; ?>
</body>
</html>
<?php
} // Kraj if ($is_direct_access)
?>