<?php
declare(strict_types=1);

require __DIR__ . '/service_dna.php';

$names = [
    'Laptop & Computer Repair', 'Mobile Repair', 'AC Repair', 'Plumbing', 'Electrical Repair',
    'CCTV & Security Installation', 'Tyre/Puncture Services', 'Vehicle Repair', 'Washing Machine Repair',
    'Refrigerator Repair', 'TV Repair', 'RO/Water Purifier Service', 'Appliance Repair', 'Home Cleaning',
    'Internet & WiFi Services',
];
$categories = [];
foreach ($names as $index => $name) $categories[] = ['id' => $index + 1, 'category_name' => $name];
$examples = [
    ['laptop', 'overheating'], ['computer', 'fan noise'], ['desktop', 'wont boot'], ['notebook', 'screen flickering'],
    ['mobile', 'cracked screen'], ['phone', 'battery draining'], ['smartphone', 'charging port'], ['iphone', 'speaker not working'],
    ['air conditioner', 'not cooling'], ['AC', 'water leaking'], ['split AC', 'making noise'], ['aircon', 'remote not working'],
    ['kitchen sink', 'water leaking'], ['bathroom pipe', 'blocked drain'], ['toilet', 'flushing issue'], ['tap', 'low water pressure'], ['pipe', 'bursting water leak'],
    ['electrical wiring', 'power outage'], ['light switch', 'sparking'], ['circuit breaker', 'keeps tripping'], ['ceiling fan', 'not working'],
    ['CCTV camera', 'no video'], ['security camera', 'offline'], ['surveillance system', 'installation'],
    ['car tyre', 'puncture'], ['bike tire', 'flat tyre'], ['wheel', 'air leak'],
    ['car engine', 'not starting'], ['motorcycle', 'brake issue'], ['vehicle', 'battery problem'],
    ['washing machine', 'not draining'], ['washer', 'drum vibrating'], ['laundry machine', 'water leakage'],
    ['refrigerator', 'not cooling'], ['fridge', 'making noise'], ['freezer', 'ice buildup'],
    ['television', 'blank screen'], ['TV', 'no sound'], ['smart TV', 'display flickering'],
    ['RO purifier', 'water not flowing'], ['water purifier', 'filter replacement'], ['reverse osmosis', 'water leakage'],
    ['appliance', 'motor not working'], ['kitchen appliance', 'power issue'],
    ['home cleaning', 'deep clean'], ['house', 'sofa cleaning'], ['apartment', 'move out cleaning'],
    ['WiFi router', 'no internet'], ['wireless internet', 'connection drops'], ['broadband router', 'slow connection'],
];
$failures = [];
foreach ($examples as $index => [$entity, $issue]) {
    $result = analyzeServiceDnaFromCategories("Please help: my $entity has $issue.", null, 'medium', $categories);
    if (empty($result['category_name'])) $failures[] = ($index + 1) . ': ' . $entity . ' / ' . $issue;
}
$ambiguity = analyzeServiceDnaFromCategories('My device has a problem.', null, 'medium', $categories);
if (!empty($ambiguity['category_name']) && empty($ambiguity['clarification_required'])) $failures[] = 'ambiguous device was assigned without clarification';
printf("ServiceDNA variant checks: %d cases; %d unclassified; ambiguity %s\n", count($examples), count($failures), empty($ambiguity['clarification_required']) ? 'FAIL' : 'PASS');
if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: $failure\n");
    exit(1);
}
echo "ServiceDNA variant checks passed.\n";
