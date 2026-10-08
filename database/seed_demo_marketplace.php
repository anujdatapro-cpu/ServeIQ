<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/encryption.php';

if (!is_dir(__DIR__ . '/backups')) {
    mkdir(__DIR__ . '/backups', 0775, true);
}

const DEMO_CUSTOMER_COUNT = 30;
const DEMO_PROVIDER_COUNT = 230;
const DEMO_PASSWORD = 'ServeIQDemo#2026';

$pdo = getDatabaseConnection();
$requiredColumns = [
    'provider_profiles' => ['latitude', 'longitude', 'location_source', 'response_time_minutes', 'response_time_source'],
    'service_requests' => ['latitude', 'longitude'],
    'users' => ['is_email_verified'],
];
foreach ($requiredColumns as $table => $expected) {
    $available = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff($expected, $available);
    if ($missing !== []) {
        throw new RuntimeException('Apply database/phase15_marketplace_discovery.sql before seeding; missing columns in ' . $table . ': ' . implode(', ', $missing));
    }
}

$additionalCategories = [
    'Washing Machine Repair' => '[ServeIQ Demo Seed] Washing machine starting, drum, drainage and leakage diagnosis.',
    'Refrigerator Repair' => '[ServeIQ Demo Seed] Refrigerator and fridge cooling, compressor and thermostat service.',
    'TV Repair' => '[ServeIQ Demo Seed] Television screen, display, sound and power repairs.',
    'RO/Water Purifier Service' => '[ServeIQ Demo Seed] RO water purifier filter, flow and leakage maintenance.',
    'CCTV & Security Installation' => '[ServeIQ Demo Seed] CCTV camera installation, wiring and home security systems.',
    'Tyre/Puncture Services' => '[ServeIQ Demo Seed] Car and bike tyre repair, punctures and replacement.',
];
$categoryUpsert = $pdo->prepare('INSERT IGNORE INTO service_categories (category_name,description,is_active) VALUES (:name,:description,1)');
foreach ($additionalCategories as $categoryName => $description) $categoryUpsert->execute(['name' => $categoryName, 'description' => $description]);
$categoryRows = $pdo->query('SELECT id, category_name FROM service_categories WHERE is_active = 1 ORDER BY category_name')->fetchAll();
$categoryIds = [];
foreach ($categoryRows as $row) $categoryIds[(string)$row['category_name']] = (int)$row['id'];
$requiredCategories = [
    'Laptop & Computer Repair', 'Mobile Repair', 'AC Repair', 'Plumbing', 'Electrical Repair',
    'Vehicle Repair', 'Appliance Repair', 'Home Cleaning', 'Internet & WiFi Services',
    'Washing Machine Repair', 'Refrigerator Repair', 'TV Repair', 'RO/Water Purifier Service',
    'CCTV & Security Installation', 'Tyre/Puncture Services',
];
foreach ($requiredCategories as $category) {
    if (!isset($categoryIds[$category])) throw new RuntimeException('Active category required by the demo dataset is missing: ' . $category);
}

$areas = [
    'Kothrud' => [18.5074, 73.8077], 'Baner' => [18.5590, 73.7868], 'Aundh' => [18.5602, 73.8075],
    'Wakad' => [18.5990, 73.7626], 'Hinjewadi' => [18.5913, 73.7389], 'Viman Nagar' => [18.5679, 73.9143],
    'Kharadi' => [18.5515, 73.9348], 'Hadapsar' => [18.5089, 73.9259], 'Kondhwa' => [18.4635, 73.8890],
    'Pimpri' => [18.6298, 73.7997], 'Chinchwad' => [18.6405, 73.7722], 'Shivajinagar' => [18.5308, 73.8475],
    'Deccan' => [18.5164, 73.8410], 'Katraj' => [18.4529, 73.8672], 'Warje' => [18.4834, 73.8077],
    'Pashan' => [18.5388, 73.7920], 'Bavdhan' => [18.5204, 73.7830], 'Koregaon Park' => [18.5362, 73.8939],
    'Camp' => [18.5130, 73.8790], 'Yerawada' => [18.5513, 73.8797], 'Dhanori' => [18.5971, 73.8860],
    'Lohegaon' => [18.5990, 73.9167], 'Magarpatta' => [18.5166, 73.9270], 'Wagholi' => [18.5793, 73.9800],
    'Bibwewadi' => [18.4766, 73.8661], 'Dhayari' => [18.4430, 73.8078],
];

$serviceGroups = [
    ['category' => 'Laptop & Computer Repair', 'count' => 20, 'label' => 'Laptop Care', 'expertise' => 'Laptop and computer repair for overheating, gaming fan noise, thermal paste replacement, cooling systems, slow performance and hardware diagnosis.', 'terms' => ['laptop', 'computer', 'overheating', 'fan', 'cooling', 'thermal', 'hardware', 'diagnosis'], 'services' => [
        ['Laptop Overheating Diagnosis', 'Gaming laptop cooling repair, overheating checks, thermal paste and loud fan diagnosis.'], ['Laptop Fan Cleaning and Replacement', 'Laptop fan noise repair, cooling system cleaning and replacement fans.'], ['Thermal Paste Replacement', 'CPU cooling service, thermal paste replacement and laptop temperature checks.'], ['Computer Hardware Diagnosis', 'PC performance repair, hardware diagnostics, SSD and memory upgrades.'], ['Laptop General Repair', 'Laptop maintenance, screen, keyboard, battery and computer repair service.'],
    ]],
    ['category' => 'Mobile Repair', 'count' => 20, 'label' => 'Mobile Repair', 'expertise' => 'Mobile phone and smartphone repairs including broken displays, cracked screens, screen replacement, batteries, charging ports and device diagnostics.', 'terms' => ['phone', 'mobile', 'display', 'screen', 'broken', 'repair'], 'services' => [
        ['Phone Display Replacement', 'Broken phone display repair, cracked screen replacement and touch panel checks.'], ['Mobile Screen Repair', 'Smartphone screen and glass replacement after drop damage.'], ['Phone Battery Replacement', 'Mobile battery, charging and power issue diagnosis and repair.'], ['Charging Port Repair', 'Phone charging port repair, charging diagnostics and connector replacement.'], ['Smartphone Hardware Diagnosis', 'Mobile phone hardware repair, speaker, camera and device troubleshooting.'],
    ]],
    ['category' => 'AC Repair', 'count' => 20, 'label' => 'Air Conditioning Service', 'expertise' => 'Air conditioning repair for AC not cooling, weak airflow, refrigerant checks, compressor diagnostics, filter cleaning and installation.', 'terms' => ['ac', 'air conditioning', 'cooling', 'not cooling', 'airflow', 'repair'], 'services' => [
        ['AC Not Cooling Repair', 'Air conditioner not cooling, weak airflow and room cooling diagnostics.'], ['AC Cooling System Diagnosis', 'AC compressor, thermostat and refrigerant system checks for poor cooling.'], ['AC Filter Cleaning', 'Air conditioning filter cleaning, seasonal service and airflow repair.'], ['AC Gas and Leak Check', 'Refrigerant level checks, cooling performance and leak inspection.'], ['AC Installation and Maintenance', 'Air conditioner installation, maintenance and cooling service.'],
    ]],
    ['category' => 'Plumbing', 'count' => 20, 'label' => 'Plumbing Service', 'expertise' => 'Home plumbing for leaking water pipes, kitchen sink leaks, dripping taps, blocked drains, bathroom fittings and water line repairs.', 'terms' => ['plumbing', 'pipe', 'leak', 'water', 'kitchen', 'tap', 'repair'], 'services' => [
        ['Kitchen Pipe Leakage Repair', 'Leaking pipe under kitchen sink, water line and pipe joint repair.'], ['Tap and Faucet Repair', 'Dripping tap, faucet and sink fitting repairs for homes.'], ['Drain Blockage and Plumbing', 'Drain clearing, water flow inspection and household plumbing.'], ['Bathroom Plumbing Repair', 'Bathroom tap, shower, pipe and water leakage repair.'], ['Water Line Diagnosis', 'Pipe leak detection, water connection and plumbing diagnostics.'],
    ]],
    ['category' => 'Electrical Repair', 'count' => 10, 'label' => 'Electrical Service', 'expertise' => 'Home electrical fault diagnosis, wiring inspection, switch and socket repair, fuse checks and safe residential electrical maintenance.', 'terms' => ['electrical', 'electrician', 'wiring', 'switch', 'socket', 'power', 'fault'], 'services' => [
        ['Electrical Fault Diagnosis', 'Electrician for home electrical faults, power issues and safety checks.'], ['Wiring and Fuse Inspection', 'Electrical wiring, fuse and circuit diagnostics and repair.'], ['Switch and Socket Repair', 'Wall switch, socket and household electrical fixture repairs.'], ['Lighting Installation', 'Residential light, fixture and electrical installation.'], ['Electrical Safety Check', 'Electrical maintenance, circuit breaker and safe power checks.'],
    ]],
    ['category' => 'Electrical Repair', 'count' => 10, 'label' => 'Ceiling Fan Service', 'expertise' => 'Ceiling fan repair for fan noise, wobbling, slow speed, intermittent stopping, capacitor faults and electrical fan wiring.', 'terms' => ['electrical', 'ceiling fan', 'fan', 'noise', 'stopping', 'repair'], 'services' => [
        ['Ceiling Fan Noise Repair', 'Noisy ceiling fan repair, balancing and motor inspection.'], ['Fan Motor and Capacitor Repair', 'Ceiling fan motor, capacitor, speed and intermittent stopping diagnosis.'], ['Fan Installation and Wiring', 'Ceiling fan fitting, wiring and electrical connection service.'], ['Ceiling Fan Maintenance', 'Fan cleaning, wobble reduction and electrical maintenance.'], ['Fan Regulator Repair', 'Fan speed control, regulator and switch repair.'],
    ]],
    ['category' => 'CCTV & Security Installation', 'count' => 10, 'label' => 'CCTV and Security', 'expertise' => 'CCTV and security camera installation, camera repair, recorder configuration, home security wiring and video surveillance checks.', 'terms' => ['cctv', 'security', 'camera', 'installation', 'wiring'], 'services' => [
        ['CCTV Installation', 'Home CCTV installation, security camera positioning and system setup.'], ['Security Camera Repair', 'CCTV camera, recorder and video feed diagnostics and repair.'], ['CCTV Wiring and Setup', 'Security system cabling, recorder setup and camera configuration.'], ['Camera Replacement and Testing', 'CCTV camera replacement, video checks and security system maintenance.'], ['Home Security System Service', 'Residential surveillance installation and camera maintenance.'],
    ]],
    ['category' => 'Tyre/Puncture Services', 'count' => 10, 'label' => 'Car Tyre Service', 'expertise' => 'Car tyre service for flat tyres, puncture repair, tyre replacement, wheel balancing, alignment and roadside tyre assistance.', 'terms' => ['car', 'tyre', 'tire', 'puncture', 'flat', 'mechanic'], 'services' => [
        ['Car Tyre Puncture Repair', 'Flat car tyre and puncture repair with wheel inspection.'], ['Car Tyre Replacement', 'Car tyre replacement, fitting and wheel balancing.'], ['Wheel Alignment and Balancing', 'Car wheel alignment, tyre wear checks and balancing.'], ['Roadside Flat Tyre Help', 'Mechanic for a flat car tyre, spare change and puncture help.'], ['Car Tyre Inspection', 'Tyre pressure, tread and puncture inspection for cars.'],
    ]],
    ['category' => 'Vehicle Repair', 'count' => 10, 'label' => 'Car Mechanic', 'expertise' => 'Car mechanic for routine service, brake inspection, battery problems, engine diagnostics and general vehicle repair.', 'terms' => ['car', 'mechanic', 'vehicle', 'engine', 'brake', 'service'], 'services' => [
        ['Car General Service', 'Car mechanic for periodic vehicle service and inspection.'], ['Car Battery Diagnosis', 'Car battery, starting and charging system repair.'], ['Brake Inspection and Repair', 'Car brake inspection, pad replacement and safety checks.'], ['Engine Diagnostics', 'Car engine warning, performance and mechanical diagnosis.'], ['Car Repair and Maintenance', 'General car repair, mechanical checks and preventative service.'],
    ]],
    ['category' => 'Tyre/Puncture Services', 'count' => 10, 'label' => 'Bike Tyre Service', 'expertise' => 'Motorcycle and bike puncture service, flat tyre repair, tube replacement, tyre fitting and wheel checks.', 'terms' => ['bike', 'motorcycle', 'tyre', 'tire', 'puncture', 'flat'], 'services' => [
        ['Bike Puncture Repair', 'Motorcycle and bike flat tyre puncture repair.'], ['Two Wheeler Tyre Replacement', 'Bike tyre and tube replacement, fitting and inspection.'], ['Bike Wheel and Tyre Check', 'Two wheeler tyre pressure, tread and wheel inspection.'], ['Roadside Bike Tyre Help', 'Motorcycle flat tyre and puncture roadside assistance.'], ['Bike Tyre Maintenance', 'Bike tyre service, balancing and wear checks.'],
    ]],
    ['category' => 'Vehicle Repair', 'count' => 10, 'label' => 'Bike Mechanic', 'expertise' => 'Bike and motorcycle mechanic for engine service, brakes, battery, chain and general two wheeler repairs.', 'terms' => ['bike', 'motorcycle', 'mechanic', 'engine', 'brake', 'repair'], 'services' => [
        ['Bike General Service', 'Motorcycle mechanic for periodic two wheeler service.'], ['Bike Brake Repair', 'Bike brake, cable and road safety repairs.'], ['Motorcycle Engine Diagnosis', 'Bike engine service, starting and performance diagnosis.'], ['Bike Battery Service', 'Motorcycle battery and electrical system checks.'], ['Two Wheeler Repair', 'General bike repair, chain adjustment and maintenance.'],
    ]],
    ['category' => 'Washing Machine Repair', 'count' => 10, 'label' => 'Washing Machine Repair', 'expertise' => 'Washing machine repair for machines not starting, loud drum noise, water leakage, drainage, motor and spin cycle problems.', 'terms' => ['washing machine', 'washer', 'leak', 'noise', 'drum', 'repair'], 'services' => [
        ['Washing Machine Not Starting Repair', 'Washer power, start cycle and control diagnosis and repair.'], ['Washing Machine Leakage Repair', 'Water leaking from washer, inlet hose and drainage repair.'], ['Drum Noise Diagnosis', 'Loud washing machine drum, vibration and spin cycle checks.'], ['Washing Machine Motor Repair', 'Washer motor, belt and spin cycle service.'], ['Washing Machine Maintenance', 'Washing machine cleaning, drainage and general repairs.'],
    ]],
    ['category' => 'Refrigerator Repair', 'count' => 10, 'label' => 'Refrigerator Repair', 'expertise' => 'Refrigerator and fridge cooling repair for warm cabinets, compressor, thermostat, refrigerant and freezer issues.', 'terms' => ['refrigerator', 'fridge', 'cooling', 'not cooling', 'compressor', 'repair'], 'services' => [
        ['Refrigerator Not Cooling Repair', 'Fridge not cooling, warm cabinet and temperature diagnosis.'], ['Fridge Compressor Diagnosis', 'Refrigerator compressor, fan and cooling system repair.'], ['Refrigerator Thermostat Repair', 'Fridge thermostat, temperature control and sensor service.'], ['Refrigerator Gas and Leak Check', 'Refrigerant checks and refrigerator cooling system inspection.'], ['Fridge General Repair', 'Refrigerator maintenance, freezer and cooling repairs.'],
    ]],
    ['category' => 'TV Repair', 'count' => 10, 'label' => 'Television Repair', 'expertise' => 'TV and television repair for broken displays, no picture, sound faults, power problems and smart TV diagnostics.', 'terms' => ['tv', 'television', 'screen', 'display', 'power', 'repair'], 'services' => [
        ['TV Not Working Diagnosis', 'Television power, no picture and startup fault diagnosis.'], ['TV Screen and Display Repair', 'TV display, backlight and screen fault repair assessment.'], ['Television Sound Repair', 'TV audio, speaker and sound connection repair.'], ['Smart TV Repair', 'Television software, network and smart TV troubleshooting.'], ['TV Power Board Repair', 'Television power and circuit board diagnostics.'],
    ]],
    ['category' => 'RO/Water Purifier Service', 'count' => 10, 'label' => 'RO Water Purifier Service', 'expertise' => 'RO and water purifier service including filter replacement, water flow problems, leakage and purifier maintenance.', 'terms' => ['ro', 'water purifier', 'filter', 'water', 'leak', 'service'], 'services' => [
        ['RO Water Purifier Service', 'RO purifier service, water flow and purification system checks.'], ['Water Filter Replacement', 'RO membrane, cartridge and water purifier filter replacement.'], ['Purifier Leakage Repair', 'Water purifier leakage and pipe connection repair.'], ['RO Water Flow Diagnosis', 'Low flow, pump and water purifier troubleshooting.'], ['Water Purifier Maintenance', 'Home RO cleaning, filter service and maintenance.'],
    ]],
    ['category' => 'Appliance Repair', 'count' => 10, 'label' => 'Home Appliance Repair', 'expertise' => 'Home appliance repair and diagnostics for small appliances, kitchen equipment and household electrical devices.', 'terms' => ['appliance', 'home', 'repair', 'diagnosis', 'electrical'], 'services' => [
        ['Home Appliance Diagnosis', 'Household appliance fault finding and repair service.'], ['Kitchen Appliance Repair', 'Small kitchen appliance and electrical equipment repair.'], ['Appliance Power Repair', 'Home appliance power, switch and wiring diagnostics.'], ['Small Appliance Maintenance', 'Inspection and repair of common household appliances.'], ['Home Device Repair', 'Residential electrical device troubleshooting and repair.'],
    ]],
    ['category' => 'Appliance Repair', 'count' => 10, 'label' => 'Microwave Repair', 'expertise' => 'Microwave oven repair for heating failure, turntable, door switches, controls and kitchen appliance faults.', 'terms' => ['microwave', 'oven', 'heating', 'door', 'repair'], 'services' => [
        ['Microwave Not Heating Repair', 'Microwave oven heating and power failure diagnosis.'], ['Microwave Door Switch Repair', 'Microwave door latch, interlock and safety switch repairs.'], ['Microwave Turntable Repair', 'Oven turntable, motor and rotation service.'], ['Microwave Control Repair', 'Microwave control panel and keypad diagnostics.'], ['Microwave General Service', 'Kitchen microwave inspection, maintenance and repair.'],
    ]],
    ['category' => 'Home Cleaning', 'count' => 10, 'label' => 'Home Cleaning', 'expertise' => 'Home cleaning service for deep cleaning, kitchens, bathrooms, move-in cleaning, dust removal and sanitization.', 'terms' => ['home', 'cleaning', 'deep clean', 'kitchen', 'bathroom', 'sanitization'], 'services' => [
        ['Home Deep Cleaning', 'Detailed home and apartment deep cleaning service.'], ['Kitchen Cleaning', 'Kitchen degreasing, cabinet and cooking area cleaning.'], ['Bathroom Cleaning', 'Bathroom, tile and fixture cleaning and sanitization.'], ['Move In Cleaning', 'Pre-move and post-move home cleaning service.'], ['Home Dusting and Sanitization', 'Dust removal, surface cleaning and home sanitization.'],
    ]],
    ['category' => 'Internet & WiFi Services', 'count' => 10, 'label' => 'WiFi and Networking', 'expertise' => 'WiFi router and home internet troubleshooting for no internet, dropped connections, router setup, network cabling and coverage.', 'terms' => ['wifi', 'wi fi', 'internet', 'router', 'network', 'connection'], 'services' => [
        ['WiFi Router Not Working Repair', 'Router power, WiFi connection and no internet troubleshooting.'], ['Home WiFi Setup', 'Wireless router configuration, secure setup and coverage checks.'], ['Internet Connection Diagnosis', 'Home broadband disconnections, modem and internet fault finding.'], ['Network Cabling and Setup', 'Computer networking, ethernet wiring and access point installation.'], ['Router Replacement and Configuration', 'Home router replacement, configuration and connection repair.'],
    ]],
];

$providerTotal = array_sum(array_column($serviceGroups, 'count'));
if ($providerTotal !== DEMO_PROVIDER_COUNT) throw new LogicException('Provider group counts must equal the declared seed total.');
$firstNames = ['Aarav','Aditi','Akash','Amit','Ananya','Arjun','Deepa','Gaurav','Isha','Karan','Kavita','Meera','Neha','Nikhil','Pooja','Priya','Rahul','Rajesh','Rohan','Sanjay','Sneha','Tanvi','Varun','Vikram','Yash','Anil','Maya','Dev','Nina','Sameer'];
$lastNames = ['Patil','Kulkarni','Deshmukh','Shinde','Joshi','Pawar','Jadhav','More','Kadam','Bhosale','Chavan','Naik','Gokhale','Kale','Salunkhe','Sawant','Thakur','Mane','Gaikwad','Inamdar','Mehta','Rane','Nair','Saxena','Shetty','Bendre','Dixit','Phadke','Soman','Apte'];
$brands = ['TechNest','QuickFix','Reliable','UrbanCare','SparkPro','PrimeWorks','SkillBridge','MetroServe','BrightPath','CareCraft','PuneChoice','TrueHands','FirstCall','BluePeak','EverReady','Neighbourhood','Precision','Everyday','SwiftHome','Trusted'];
$pricePoints = [299, 349, 399, 449, 499, 549, 599, 699, 749, 799, 899, 999, 1099, 1299, 1499, 1799, 1999, 2499];
$availability = ['available','available','available','available','available','available','available','busy','busy','offline'];
$reviewCounts = [0,2,3,3,3,5,3,2,3,3];
$ratings = [3,4,5,4,5,4,3,5,4,5];

$avatarDir = dirname(__DIR__) . '/uploads/profiles';
if (!is_dir($avatarDir) && !mkdir($avatarDir, 0775, true) && !is_dir($avatarDir)) throw new RuntimeException('Cannot create demo avatar directory.');
$passwordHash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);
$created = ['customers' => 0, 'providers' => 0, 'services' => 0, 'fixture_requests' => 0, 'bookings' => 0, 'reviews' => 0, 'avatars' => 0];
$providerByNumber = [];
$providerNumber = 0;

$pdo->beginTransaction();
try {
    $insertUser = $pdo->prepare('INSERT INTO users (name,email,password,role,is_email_verified) VALUES (:name,:email,:password,:role,1)');
    $findUser = $pdo->prepare('SELECT id, role FROM users WHERE email = :email LIMIT 1');
    $updateUser = $pdo->prepare('UPDATE users SET name=:name,password=:password,is_email_verified=1 WHERE id=:id');
    $customerIds = [];
    for ($i = 1; $i <= DEMO_CUSTOMER_COUNT; $i++) {
        $email = sprintf('demo.customer%02d@serveiq.local', $i);
        $name = ($i === 1) ? 'Test Customer' : sprintf('Demo Customer %02d', $i);
        $findUser->execute(['email' => $email]);
        $existing = $findUser->fetch();
        if ($existing && $existing['role'] !== 'customer') throw new RuntimeException('Demo customer email is already used by another account role: ' . $email);
        if ($existing) {
            $id = (int)$existing['id'];
            $updateUser->execute(['name' => $name, 'password' => $passwordHash, 'id' => $id]);
        } else {
            $insertUser->execute(['name' => $name, 'email' => $email, 'password' => $passwordHash, 'role' => 'customer']);
            $id = (int)$pdo->lastInsertId();
            $created['customers']++;
        }
        $customerIds[] = $id;
    }

    $findProvider = $pdo->prepare('SELECT id,role FROM users WHERE email=:email LIMIT 1');
    $profileFind = $pdo->prepare('SELECT id FROM provider_profiles WHERE user_id=:user_id LIMIT 1');
    $profileUpsert = $pdo->prepare(
        'INSERT INTO provider_profiles (user_id,business_name,phone,address,city,area,latitude,longitude,location_source,experience_years,description,profile_image,availability_status,verification_status,response_time_minutes,response_time_source)
         VALUES (:user_id,:business_name,:phone,:address,:city,:area,:latitude,:longitude,\'demo_estimate\',:experience_years,:description,:profile_image,:availability_status,\'approved\',:response_time_minutes,:response_time_source)
         ON DUPLICATE KEY UPDATE business_name=VALUES(business_name),phone=VALUES(phone),address=VALUES(address),city=VALUES(city),area=VALUES(area),latitude=VALUES(latitude),longitude=VALUES(longitude),location_source=\'demo_estimate\',experience_years=VALUES(experience_years),description=VALUES(description),profile_image=VALUES(profile_image),availability_status=VALUES(availability_status),verification_status=\'approved\',response_time_minutes=VALUES(response_time_minutes),response_time_source=VALUES(response_time_source)'
    );
    $serviceFind = $pdo->prepare('SELECT id FROM services WHERE provider_id=:provider_id AND service_name=:service_name LIMIT 1');
    $serviceInsert = $pdo->prepare('INSERT INTO services (provider_id,category_id,service_name,description,base_price,is_active) VALUES (:provider_id,:category_id,:service_name,:description,:base_price,1)');
$serviceUpdate = $pdo->prepare('UPDATE services SET category_id=:category_id,description=:description,base_price=:base_price,is_active=1 WHERE id=:id');

    foreach ($serviceGroups as $groupIndex => $group) {
        $categoryId = $categoryIds[$group['category']];
        for ($withinGroup = 0; $withinGroup < $group['count']; $withinGroup++) {
            $number = ++$providerNumber;
            $email = sprintf('demo.provider%03d@serveiq.local', $number);
            $first = $firstNames[($number * 7 + $groupIndex) % count($firstNames)];
            $last = $lastNames[($number * 11 + $groupIndex) % count($lastNames)];
            $name = sprintf('%s %s (Demo %03d)', $first, $last, $number);
            $areaNames = array_keys($areas);
            $area = $areaNames[($number * 13 + $groupIndex) % count($areaNames)];
            [$baseLat, $baseLon] = $areas[$area];
            $lat = round($baseLat + ((($number % 7) - 3) * 0.00055), 7);
            $lon = round($baseLon + (((($number * 3) % 7) - 3) * 0.00055), 7);
            $business = sprintf('%s %s %s Demo %03d', $brands[($number * 3 + $groupIndex) % count($brands)], $area, $group['label'], $number);
            $availabilityStatus = $availability[$withinGroup % count($availability)];
            $imageName = sprintf('demo-provider-%03d.svg', $number);
            $imagePath = 'uploads/profiles/' . $imageName;
            $initials = mb_substr($first, 0, 1, 'UTF-8') . mb_substr($last, 0, 1, 'UTF-8');
            $color = sprintf('#%02x%02x%02x', 70 + (($number * 29) % 100), 85 + (($number * 17) % 100), 115 + (($number * 11) % 90));
            $safeInitials = htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160"><rect width="160" height="160" rx="80" fill="' . $color . '"/><text x="80" y="96" text-anchor="middle" font-family="Arial,sans-serif" font-size="52" font-weight="700" fill="#fff">' . $safeInitials . '</text></svg>';
            if (!file_exists(dirname(__DIR__) . '/' . $imagePath)) {
                file_put_contents(dirname(__DIR__) . '/' . $imagePath, $svg, LOCK_EX);
                $created['avatars']++;
            }

            $findProvider->execute(['email' => $email]);
            $existingUser = $findProvider->fetch();
            if ($existingUser && $existingUser['role'] !== 'provider') throw new RuntimeException('Demo provider email is already used by another account role: ' . $email);
            if ($existingUser) {
                $userId = (int)$existingUser['id'];
                $updateUser->execute(['name' => $name, 'password' => $passwordHash, 'id' => $userId]);
            } else {
                $insertUser->execute(['name' => $name, 'email' => $email, 'password' => $passwordHash, 'role' => 'provider']);
                $userId = (int)$pdo->lastInsertId();
                $created['providers']++;
            }
            $profileFind->execute(['user_id' => $userId]);
            $existingProfile = $profileFind->fetch();
            $profileId = $existingProfile ? (int)$existingProfile['id'] : 0;
            $profileUpsert->execute([
                'user_id' => $userId, 'business_name' => $business,
                'phone' => encryptSensitiveData((string)(9000000000 + $number)),
                'address' => encryptSensitiveData('Shop ' . (1 + ($number % 48)) . ', ' . $area . ', Pune, Maharashtra'),
                'city' => 'Pune', 'area' => $area, 'latitude' => $lat, 'longitude' => $lon,
                'experience_years' => 1 + (($number * 7) % 24),
                'description' => $group['expertise'] . ' Synthetic ServeIQ demo profile ' . sprintf('listing %03d; ', $number) . 'contact details are fictional.',
                'profile_image' => $imagePath, 'availability_status' => $availabilityStatus,
                'response_time_minutes' => 10 + (($number * 7) % 51), 'response_time_source' => 'demo_estimate',
            ]);
            if ($profileId === 0) $profileId = (int)$pdo->lastInsertId();
            $providerByNumber[$number] = ['user_id' => $userId, 'profile_id' => $profileId, 'group' => $group, 'category_id' => $categoryId];

            $serviceCount = 2 + ($number % 3);
            $serviceTotal = count($group['services']);
            for ($offset = 0; $offset < $serviceCount; $offset++) {
                $service = $group['services'][($withinGroup + $offset) % $serviceTotal];
                $price = $pricePoints[($number * 5 + $offset * 7 + $groupIndex) % count($pricePoints)];
                $description = $service[1] . ' Synthetic demo service listing ' . sprintf('%03d', $number) . '.';
                $serviceFind->execute(['provider_id' => $profileId, 'service_name' => $service[0]]);
                $existingService = $serviceFind->fetch();
                if ($existingService) {
                    $serviceUpdate->execute(['category_id' => $categoryId, 'description' => $description, 'base_price' => $price, 'id' => (int)$existingService['id']]);
                } else {
                    $serviceInsert->execute(['provider_id' => $profileId, 'category_id' => $categoryId, 'service_name' => $service[0], 'description' => $description, 'base_price' => $price]);
                    $created['services']++;
                }
            }
        }
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}

// A realistic synthetic reputation record is backed by a completed booking,
// a provider response, request ownership, and status history. Every review is
// visibly identified as demo content; no matching_results are written here.
$requestFind = $pdo->prepare('SELECT id FROM service_requests WHERE customer_id=:customer_id AND description=:description LIMIT 1');
$requestInsert = $pdo->prepare('INSERT INTO service_requests (customer_id,category_id,title,description,city,area,latitude,longitude,urgency,contact_preference,status) VALUES (:customer_id,:category_id,:title,:description,\'Pune\',:area,:latitude,:longitude,\'medium\',\'email\',\'completed\')');
$responseInsert = $pdo->prepare('INSERT INTO provider_responses (request_id,provider_id,diagnosis,confidence,recommended_service,estimated_price,estimated_time,notes,status) VALUES (:request_id,:provider_id,:diagnosis,88,:recommended_service,:estimated_price,\'25 minutes (demo)\',\'Synthetic completed booking fixture for demo ratings.\',\'accepted\')');
$bookingInsert = $pdo->prepare('INSERT INTO bookings (request_id,customer_id,provider_id,service_id,response_id,scheduled_date,scheduled_time,notes,accepted_at,started_at,completed_at,status) VALUES (:request_id,:customer_id,:provider_id,:service_id,:response_id,:scheduled_date,\'10:00:00\',\'Synthetic completed demo service fixture.\',:accepted_at,:started_at,:completed_at,\'completed\')');
$historyInsert = $pdo->prepare('INSERT INTO booking_status_history (booking_id,old_status,new_status,changed_by,note) VALUES (:booking_id,:old_status,:new_status,:changed_by,:note)');
$reviewInsert = $pdo->prepare('INSERT INTO reviews (booking_id,customer_id,provider_id,rating,review,status) VALUES (:booking_id,:customer_id,:provider_id,:rating,:review,\'published\')');
$bookingFind = $pdo->prepare('SELECT id FROM bookings WHERE request_id=:request_id LIMIT 1');
$reviewFind = $pdo->prepare('SELECT id FROM reviews WHERE booking_id=:booking_id LIMIT 1');
$allServices = $pdo->prepare('SELECT id,service_name,base_price FROM services WHERE provider_id=:provider_id AND category_id=:category_id AND is_active=1 ORDER BY id');
$reviewText = '[DEMO REVIEW] Synthetic review created only to exercise ServeIQ demo ratings and review display.';

foreach ($providerByNumber as $number => $provider) {
    $count = $reviewCounts[($number - 1) % count($reviewCounts)];
    if ($count === 0) continue;
    $allServices->execute(['provider_id' => $provider['profile_id'], 'category_id' => $provider['category_id']]);
    $services = $allServices->fetchAll();
    if ($services === []) throw new RuntimeException('Demo provider is missing a service required for its completed review fixtures.');
    // Use the profile's saved area and coordinates so each fixture is coherent with its selected provider.
    $profileStmt = $pdo->prepare('SELECT area,latitude,longitude FROM provider_profiles WHERE id=:id');
    $profileStmt->execute(['id' => $provider['profile_id']]);
    $profileLocation = $profileStmt->fetch();
    $area = (string)$profileLocation['area'];
    for ($reviewIndex = 1; $reviewIndex <= $count; $reviewIndex++) {
        $customerId = $customerIds[($number * 3 + $reviewIndex - 1) % count($customerIds)];
        $fixtureKey = sprintf('[DEMO FIXTURE: provider-%03d review-%02d] ', $number, $reviewIndex);
        $fixtureDescription = $fixtureKey . $provider['group']['expertise'];
        $requestFind->execute(['customer_id' => $customerId, 'description' => $fixtureDescription]);
        $requestId = (int)($requestFind->fetchColumn() ?: 0);
        if ($requestId === 0) {
            $requestInsert->execute([
                'customer_id' => $customerId, 'category_id' => $provider['category_id'],
                'title' => 'Demo completed ' . $provider['group']['label'] . ' service', 'description' => $fixtureDescription,
                'area' => $area, 'latitude' => $profileLocation['latitude'], 'longitude' => $profileLocation['longitude'],
            ]);
            $requestId = (int)$pdo->lastInsertId();
            $created['fixture_requests']++;
        }
        $bookingFind->execute(['request_id' => $requestId]);
        $bookingId = (int)($bookingFind->fetchColumn() ?: 0);
        if ($bookingId > 0) {
            $reviewFind->execute(['booking_id' => $bookingId]);
            if ($reviewFind->fetchColumn()) continue;
            throw new RuntimeException('Incomplete demo fixture booking detected; review was not created. Inspect request ' . $requestId . ' before rerunning.');
        }
        $service = $services[($number + $reviewIndex) % count($services)];
        $responseInsert->execute([
            'request_id' => $requestId, 'provider_id' => $provider['profile_id'],
            'diagnosis' => 'Demo fixture diagnosis: ' . $provider['group']['label'],
            'recommended_service' => $service['service_name'],
            'estimated_price' => (float)($service['base_price'] ?? 499),
        ]);
        $responseId = (int)$pdo->lastInsertId();
        $completedAt = date('Y-m-d H:i:s', time() - (86400 * ($number + $reviewIndex)));
        $bookingInsert->execute([
            'request_id' => $requestId, 'customer_id' => $customerId, 'provider_id' => $provider['profile_id'],
            'service_id' => (int)$service['id'], 'response_id' => $responseId,
            'scheduled_date' => substr($completedAt, 0, 10), 'accepted_at' => $completedAt,
            'started_at' => $completedAt, 'completed_at' => $completedAt,
        ]);
        $bookingId = (int)$pdo->lastInsertId();
        $history = [
            [null, 'pending', $customerId], ['pending', 'accepted', $provider['user_id']],
            ['accepted', 'in_progress', $provider['user_id']], ['in_progress', 'completed', $provider['user_id']],
        ];
        foreach ($history as [$oldStatus, $newStatus, $actorId]) {
            $historyInsert->execute(['booking_id' => $bookingId, 'old_status' => $oldStatus, 'new_status' => $newStatus, 'changed_by' => $actorId, 'note' => 'DEMO fixture booking history.']);
        }
        $rating = $ratings[($number + $reviewIndex - 1) % count($ratings)];
        $reviewInsert->execute(['booking_id' => $bookingId, 'customer_id' => $customerId, 'provider_id' => $provider['profile_id'], 'rating' => $rating, 'review' => $reviewText]);
        $created['bookings']++;
        $created['reviews']++;
    }
}

// Keep provider averages visibly diverse while each integer-star review stays
// a genuine child of its own completed demo booking.
$demoReviewRows = $pdo->prepare(
    "SELECT r.id,u.email FROM reviews r INNER JOIN provider_profiles pp ON pp.id=r.provider_id
     INNER JOIN users u ON u.id=pp.user_id WHERE u.email REGEXP '^demo[.]provider[0-9]{3}@serveiq[.]local$'
     AND r.review=:label ORDER BY r.provider_id,r.id"
);
$demoReviewRows->execute(['label' => $reviewText]);
$rowsByProvider = [];
foreach ($demoReviewRows->fetchAll() as $row) $rowsByProvider[$row['email']][] = (int)$row['id'];
$ratingUpdate = $pdo->prepare('UPDATE reviews SET rating=:rating WHERE id=:id');
foreach ($rowsByProvider as $email => $reviewIds) {
    if (!preg_match('/demo[.]provider([0-9]{3})@serveiq[.]local/', $email, $emailParts)) continue;
    $number = (int)$emailParts[1];
    $count = count($reviewIds);
    if ($count === 2) $targetAverage = $number % 2 === 0 ? 3.5 : 4.5;
    elseif ($count === 5) $targetAverage = [3.2,4.2,4.6,4.8][(intdiv($number - 1, 10)) % 4];
    else $targetAverage = [3.7,4.0,4.3,4.7][$number % 4];
    $targetSum = (int)round($targetAverage * $count);
    $star = intdiv($targetSum, $count);
    $extraStars = $targetSum % $count;
    foreach ($reviewIds as $index => $reviewId) $ratingUpdate->execute(['rating' => $star + ($index < $extraStars ? 1 : 0), 'id' => $reviewId]);
}

seedTestCustomerDemoWorkflow($pdo, $customerIds[0]);

echo "ServeIQ Demo Seed\n";
foreach ($created as $type => $count) printf("%s created: %d\n", ucfirst(str_replace('_', ' ', $type)), $count);
printf("Customers present: %d\nProviders present: %d\nActive categories covered: %d\n", DEMO_CUSTOMER_COUNT, DEMO_PROVIDER_COUNT, count($requiredCategories));
echo "Demo login password: " . DEMO_PASSWORD . "\n\nCoverage (providers / available / active services):\n";
$coverage = $pdo->query(
    "SELECT c.category_name, COUNT(DISTINCT CASE WHEN u.id IS NOT NULL THEN pp.id END) AS providers,
            COUNT(DISTINCT CASE WHEN u.id IS NOT NULL AND pp.availability_status='available' THEN pp.id END) AS available,
            COUNT(DISTINCT CASE WHEN u.id IS NOT NULL THEN s.id END) AS services
     FROM service_categories c LEFT JOIN services s ON s.category_id=c.id AND s.is_active=1
     LEFT JOIN provider_profiles pp ON pp.id=s.provider_id AND pp.verification_status='approved'
     LEFT JOIN users u ON u.id=pp.user_id AND u.email REGEXP '^demo[.]provider[0-9]{3}@serveiq[.]local$'
     WHERE c.is_active=1 GROUP BY c.id,c.category_name ORDER BY c.category_name"
)->fetchAll();
foreach ($coverage as $row) printf("%s: %d / %d / %d\n", $row['category_name'], $row['providers'], $row['available'], $row['services']);

/**
 * Ensures Test Customer (demo.customer01@serveiq.local) has a connected, realistic workflow:
 * 1) Completed Service #1: Laptop Repair + 5/5 Review
 * 2) Completed Service #2: AC Repair + 5/5 Review
 * 3) Upcoming Accepted Booking #3: RO Water Purifier Repair (scheduled, accepted, receipt ready)
 */
function seedTestCustomerDemoWorkflow(PDO $pdo, int $customerId): void
{
    $categories = [
        'laptop' => 'Laptop & Computer Repair',
        'ac' => 'AC Repair',
        'ro' => 'RO/Water Purifier Service',
    ];

    $catIds = [];
    $catStmt = $pdo->prepare('SELECT id FROM service_categories WHERE category_name = :name LIMIT 1');
    foreach ($categories as $key => $catName) {
        $catStmt->execute(['name' => $catName]);
        $catIds[$key] = (int)$catStmt->fetchColumn();
    }

    $provStmt = $pdo->prepare(
        "SELECT pp.id AS provider_id, pp.user_id, s.id AS service_id, s.base_price, s.service_name
         FROM provider_profiles pp
         JOIN services s ON s.provider_id = pp.id AND s.is_active = 1
         WHERE pp.verification_status = 'approved' AND s.category_id = :cat_id
         ORDER BY pp.id ASC LIMIT 1"
    );

    $laptopProv = ($provStmt->execute(['cat_id' => $catIds['laptop']])) ? $provStmt->fetch() : null;
    $acProv = ($provStmt->execute(['cat_id' => $catIds['ac']])) ? $provStmt->fetch() : null;
    $roProv = ($provStmt->execute(['cat_id' => $catIds['ro']])) ? $provStmt->fetch() : null;

    if (!$laptopProv || !$acProv || !$roProv) {
        return;
    }

    $workflows = [
        [
            'title' => 'Gaming Laptop Overheating & Loud Fan Noise',
            'description' => 'My gaming laptop is overheating while playing games and the cooling fan is making a very loud noise.',
            'category_id' => $catIds['laptop'],
            'provider_id' => (int)$laptopProv['provider_id'],
            'provider_user_id' => (int)$laptopProv['user_id'],
            'service_id' => (int)$laptopProv['service_id'],
            'service_name' => (string)$laptopProv['service_name'],
            'price' => (float)($laptopProv['base_price'] ?: 799),
            'city' => 'Pune', 'area' => 'Kothrud',
            'urgency' => 'medium',
            'status' => 'completed',
            'booking_status' => 'completed',
            'days_ago' => 5,
            'scheduled_date' => date('Y-m-d', strtotime('-5 days')),
            'scheduled_time' => '10:00:00',
            'dna_type' => 'Thermal / Overheating',
            'dna_entity' => 'Gaming Laptop',
            'dna_symptoms' => ['overheating', 'loud fan noise', 'thermal throttling'],
            'review_rating' => 5,
            'review_text' => 'Excellent service. The technician diagnosed the overheating issue quickly, replaced the thermal paste, and cleaned the cooling fan. My laptop runs cool and quiet now!',
        ],
        [
            'title' => 'Split AC Not Cooling & Blowing Warm Air',
            'description' => 'My split AC is not cooling properly and the indoor unit is blowing warm air.',
            'category_id' => $catIds['ac'],
            'provider_id' => (int)$acProv['provider_id'],
            'provider_user_id' => (int)$acProv['user_id'],
            'service_id' => (int)$acProv['service_id'],
            'service_name' => (string)$acProv['service_name'],
            'price' => (float)($acProv['base_price'] ?: 1299),
            'city' => 'Pune', 'area' => 'Baner',
            'urgency' => 'high',
            'status' => 'completed',
            'booking_status' => 'completed',
            'days_ago' => 2,
            'scheduled_date' => date('Y-m-d', strtotime('-2 days')),
            'scheduled_time' => '14:00:00',
            'dna_type' => 'Cooling System Failure',
            'dna_entity' => 'Split Air Conditioner',
            'dna_symptoms' => ['not cooling', 'warm airflow', 'compressor issue'],
            'review_rating' => 5,
            'review_text' => 'Very good service. The issue with the cooling system was identified quickly and the AC was restored properly. Great communication throughout!',
        ],
        [
            'title' => 'RO Water Purifier Unusual Noise & Reduced Flow',
            'description' => 'RO water purifier is making an unusual vibration noise and water flow from the tap has reduced significantly.',
            'category_id' => $catIds['ro'],
            'provider_id' => (int)$roProv['provider_id'],
            'provider_user_id' => (int)$roProv['user_id'],
            'service_id' => (int)$roProv['service_id'],
            'service_name' => (string)$roProv['service_name'],
            'price' => (float)($roProv['base_price'] ?: 499),
            'city' => 'Pune', 'area' => 'Kothrud',
            'urgency' => 'medium',
            'status' => 'matched',
            'booking_status' => 'accepted',
            'days_ago' => -1,
            'scheduled_date' => date('Y-m-d', strtotime('+1 day')),
            'scheduled_time' => '11:00:00',
            'dna_type' => 'Purifier Filtration & Pump Noise',
            'dna_entity' => 'RO Water Purifier',
            'dna_symptoms' => ['unusual noise', 'reduced water flow', 'vibration'],
            'review_rating' => null,
            'review_text' => null,
        ],
    ];

    $reqFind = $pdo->prepare('SELECT id FROM service_requests WHERE customer_id = :cid AND title = :title LIMIT 1');
    $reqInsert = $pdo->prepare(
        'INSERT INTO service_requests (customer_id, category_id, title, description, city, area, urgency, contact_preference, status)
         VALUES (:cid, :cat_id, :title, :desc, :city, :area, :urgency, \'email\', :status)'
    );
    $reqUpdate = $pdo->prepare('UPDATE service_requests SET category_id=:cat_id, description=:desc, city=:city, area=:area, urgency=:urgency, status=:status WHERE id=:id');

    $dnaInsert = $pdo->prepare(
        'INSERT INTO problem_fingerprints (request_id, detected_category_id, problem_type, affected_entity, symptoms, context, keywords, possible_service_types, location_context, confidence_score, user_urgency, detected_urgency, evidence, urgency_score, fingerprint_data, engine_version, analysis_method)
         VALUES (:rid, :cat_id, :ptype, :entity, :symptoms, \'["home_usage"]\', \'["repair"]\', \'["service"]\', \'{"city":"Pune"}\', 90, :urgency, :urgency, \'{"signals":[]}\', 80, \'{}\', \'rule-based-1.0\', \'rule_based_v1\')
         ON DUPLICATE KEY UPDATE detected_category_id=VALUES(detected_category_id), problem_type=VALUES(problem_type), affected_entity=VALUES(affected_entity), symptoms=VALUES(symptoms)'
    );

    $respFind = $pdo->prepare('SELECT id FROM provider_responses WHERE request_id = :rid AND provider_id = :pid LIMIT 1');
    $respInsert = $pdo->prepare(
        'INSERT INTO provider_responses (request_id, provider_id, diagnosis, confidence, recommended_service, estimated_price, estimated_time, notes, status)
         VALUES (:rid, :pid, :diag, 90, :rec, :price, \'30 minutes\', \'ServeIQ demo diagnosis.\', \'accepted\')'
    );

    $bkFind = $pdo->prepare('SELECT id FROM bookings WHERE request_id = :rid LIMIT 1');
    $bkInsert = $pdo->prepare(
        'INSERT INTO bookings (request_id, customer_id, provider_id, service_id, response_id, scheduled_date, scheduled_time, notes, status, accepted_at, started_at, completed_at)
         VALUES (:rid, :cid, :pid, :sid, :res_id, :sdate, :stime, :notes, :bstatus, :accepted_at, :started_at, :completed_at)'
    );
    $bkUpdate = $pdo->prepare(
        'UPDATE bookings SET scheduled_date=:sdate, scheduled_time=:stime, notes=:notes, status=:bstatus, accepted_at=:accepted_at, started_at=:started_at, completed_at=:completed_at WHERE id=:id'
    );

    $histInsert = $pdo->prepare('INSERT INTO booking_status_history (booking_id, old_status, new_status, changed_by, note) VALUES (:bid, :old_s, :new_s, :uid, :note)');
    $histFind = $pdo->prepare('SELECT COUNT(*) FROM booking_status_history WHERE booking_id = :bid');

    $revFind = $pdo->prepare('SELECT id FROM reviews WHERE booking_id = :bid LIMIT 1');
    $revInsert = $pdo->prepare('INSERT INTO reviews (booking_id, customer_id, provider_id, rating, review, status) VALUES (:bid, :cid, :pid, :rating, :rtext, \'published\')');

    foreach ($workflows as $wf) {
        $reqFind->execute(['cid' => $customerId, 'title' => $wf['title']]);
        $requestId = (int)($reqFind->fetchColumn() ?: 0);
        if ($requestId === 0) {
            $reqInsert->execute([
                'cid' => $customerId, 'cat_id' => $wf['category_id'],
                'title' => $wf['title'], 'desc' => $wf['description'],
                'city' => $wf['city'], 'area' => $wf['area'],
                'urgency' => $wf['urgency'], 'status' => $wf['status'],
            ]);
            $requestId = (int)$pdo->lastInsertId();
        } else {
            $reqUpdate->execute([
                'cat_id' => $wf['category_id'], 'desc' => $wf['description'],
                'city' => $wf['city'], 'area' => $wf['area'],
                'urgency' => $wf['urgency'], 'status' => $wf['status'], 'id' => $requestId,
            ]);
        }

        $dnaInsert->execute([
            'rid' => $requestId, 'cat_id' => $wf['category_id'],
            'ptype' => $wf['dna_type'], 'entity' => $wf['dna_entity'],
            'symptoms' => json_encode($wf['dna_symptoms']), 'urgency' => $wf['urgency'],
        ]);

        $respFind->execute(['rid' => $requestId, 'pid' => $wf['provider_id']]);
        $responseId = (int)($respFind->fetchColumn() ?: 0);
        if ($responseId === 0) {
            $respInsert->execute([
                'rid' => $requestId, 'pid' => $wf['provider_id'],
                'diag' => 'Diagnosis and inspection for ' . $wf['title'],
                'rec' => $wf['service_name'], 'price' => $wf['price'],
            ]);
            $responseId = (int)$pdo->lastInsertId();
        }

        $timestamp = date('Y-m-d H:i:s', time() - (86400 * max(0, $wf['days_ago'])));
        $acceptedAt = $wf['booking_status'] === 'accepted' ? date('Y-m-d H:i:s') : $timestamp;
        $startedAt = $wf['booking_status'] === 'completed' ? $timestamp : null;
        $completedAt = $wf['booking_status'] === 'completed' ? $timestamp : null;

        $bkFind->execute(['rid' => $requestId]);
        $bookingId = (int)($bkFind->fetchColumn() ?: 0);
        if ($bookingId === 0) {
            $bkInsert->execute([
                'rid' => $requestId, 'cid' => $customerId, 'pid' => $wf['provider_id'],
                'sid' => $wf['service_id'], 'res_id' => $responseId,
                'sdate' => $wf['scheduled_date'], 'stime' => $wf['scheduled_time'],
                'notes' => 'Customer appointment note for ' . $wf['title'],
                'bstatus' => $wf['booking_status'],
                'accepted_at' => $acceptedAt, 'started_at' => $startedAt, 'completed_at' => $completedAt,
            ]);
            $bookingId = (int)$pdo->lastInsertId();
        } else {
            $bkUpdate->execute([
                'sdate' => $wf['scheduled_date'], 'stime' => $wf['scheduled_time'],
                'notes' => 'Customer appointment note for ' . $wf['title'],
                'bstatus' => $wf['booking_status'],
                'accepted_at' => $acceptedAt, 'started_at' => $startedAt, 'completed_at' => $completedAt,
                'id' => $bookingId,
            ]);
        }

        $histFind->execute(['bid' => $bookingId]);
        if ((int)$histFind->fetchColumn() === 0) {
            if ($wf['booking_status'] === 'completed') {
                $histTransitions = [
                    [null, 'pending', $customerId],
                    ['pending', 'accepted', $wf['provider_user_id']],
                    ['accepted', 'in_progress', $wf['provider_user_id']],
                    ['in_progress', 'completed', $wf['provider_user_id']],
                ];
            } else {
                $histTransitions = [
                    [null, 'pending', $customerId],
                    ['pending', 'accepted', $wf['provider_user_id']],
                ];
            }
            foreach ($histTransitions as [$oldS, $newS, $actorId]) {
                $histInsert->execute([
                    'bid' => $bookingId, 'old_s' => $oldS, 'new_s' => $newS,
                    'uid' => $actorId, 'note' => 'Demo workflow state change.',
                ]);
            }
        }

        if ($wf['review_rating'] !== null) {
            $revFind->execute(['bid' => $bookingId]);
            $reviewId = (int)($revFind->fetchColumn() ?: 0);
            if ($reviewId === 0) {
                $revInsert->execute([
                    'bid' => $bookingId, 'cid' => $customerId, 'pid' => $wf['provider_id'],
                    'rating' => $wf['review_rating'], 'rtext' => $wf['review_text'],
                ]);
            }
        }
    }
}
