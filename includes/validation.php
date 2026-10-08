<?php
declare(strict_types=1);

function validateRequiredValue(string $value): bool
{
    return trim($value) !== '';
}

function validateName(string $name): bool
{
    $name = trim($name);
    return mb_strlen($name) >= 2 && mb_strlen($name) <= 120 && (bool)preg_match("/^[\p{L}\p{M} .'-]+$/u", $name);
}

function validateEmail(string $email): bool
{
    $email = trim($email);
    return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePhone(string $phone): bool
{
    return (bool)preg_match('/^[6-9][0-9]{9}$/', trim($phone));
}

function validateProblemDescription(string $description): bool
{
    $description = trim($description);
    $length = mb_strlen($description);
    if ($length < 20 || $length > 5000 || preg_match('/^(.)\1{11,}$/us', preg_replace('/\s+/u', '', $description) ?? $description)) {
        return false;
    }
    return !preg_match('/^(?:lorem ipsum|asdf|test(?:ing)?|no details available)(?:[.! ]*)$/iu', $description);
}

function validateServiceRequest(array $input): array
{
    $errors = [];
    $title = trim((string)($input['title'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $city = trim((string)($input['city'] ?? ''));
    if (mb_strlen($title) < 2 || mb_strlen($title) > 180) {
        $errors['title'] = 'Problem title must be between 2 and 180 characters.';
    }
    if (!validateProblemDescription($description)) {
        $errors['description'] = 'Describe the problem in at least 20 characters, using up to 5000 characters.';
    }
    if ($city === '' || mb_strlen($city) > 100) {
        $errors['city'] = 'City is required and must be 100 characters or fewer.';
    }
    if (mb_strlen((string)($input['area'] ?? '')) > 100) {
        $errors['area'] = 'Area / Locality must be 100 characters or fewer.';
    }
    if (mb_strlen((string)($input['address'] ?? '')) > 255) {
        $errors['address'] = 'Address must be 255 characters or fewer.';
    }
    if (!in_array((string)($input['urgency'] ?? ''), ['low', 'medium', 'high', 'emergency'], true)) {
        $errors['urgency'] = 'Choose a valid urgency level.';
    }
    if (!in_array((string)($input['contact_preference'] ?? ''), ['phone', 'email'], true)) {
        $errors['contact_preference'] = 'Choose phone or email as the contact preference.';
    }
    return $errors;
}

function validateProviderProfile(array $input): array
{
    $errors = [];
    $businessName = trim((string)($input['business_name'] ?? ''));
    $address = trim((string)($input['address'] ?? ''));
    $city = trim((string)($input['city'] ?? ''));
    $experience = filter_var($input['experience_years'] ?? null, FILTER_VALIDATE_INT);
    if (mb_strlen($businessName) < 2 || mb_strlen($businessName) > 160) $errors['business_name'] = 'Business name must be between 2 and 160 characters.';
    if (!validatePhone((string)($input['phone'] ?? ''))) $errors['phone'] = 'Enter a valid 10-digit Indian mobile number starting with 6, 7, 8, or 9.';
    if ($address === '' || mb_strlen($address) > 255) $errors['address'] = 'Address is required and must be 255 characters or fewer.';
    if ($city === '' || mb_strlen($city) > 100) $errors['city'] = 'City is required and must be 100 characters or fewer.';
    if (mb_strlen((string)($input['area'] ?? '')) > 100) $errors['area'] = 'Area / Locality must be 100 characters or fewer.';
    if ($experience === false || $experience < 0 || $experience > 80) $errors['experience_years'] = 'Years of experience must be between 0 and 80.';
    if (mb_strlen(trim((string)($input['description'] ?? ''))) > 5000) $errors['description'] = 'Business description cannot exceed 5000 characters.';
    if (!in_array((string)($input['availability_status'] ?? ''), ['available', 'busy', 'offline'], true)) $errors['availability_status'] = 'Choose a valid availability status.';
    return $errors;
}

function validateServiceData(array $input): array
{
    $errors = [];
    $name = trim((string)($input['service_name'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $price = trim((string)($input['base_price'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 160) $errors['service_name'] = 'Service name must be between 2 and 160 characters.';
    if ($description === '' || mb_strlen($description) > 5000) $errors['description'] = 'Service description is required and must be 5000 characters or fewer.';
    if (!preg_match('/^[0-9]{1,8}(?:\.[0-9]{1,2})?$/', $price)) $errors['base_price'] = 'Base price must be non-negative with up to two decimal places.';
    if (!in_array((string)($input['is_active'] ?? ''), ['0', '1'], true)) $errors['is_active'] = 'Choose a valid service status.';
    return $errors;
}

function validateCategoryData(array $input): array
{
    $errors = [];
    $name = trim((string)($input['category_name'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    if ($name === '' || mb_strlen($name) > 120) {
        $errors['category_name'] = 'Category name is required and must be 120 characters or fewer.';
    }
    if (mb_strlen($description) > 5000) {
        $errors['description'] = 'Category description cannot exceed 5000 characters.';
    }
    return $errors;
}

function validateModerationNote(string $note): bool
{
    return mb_strlen(trim($note)) <= 2000;
}

function validateReviewData(mixed $rating, string $comment): array
{
    $errors = [];
    $validRating = filter_var($rating, FILTER_VALIDATE_INT);
    if ($validRating === false || $validRating < 1 || $validRating > 5) $errors['rating'] = 'Rating must be an integer from 1 to 5.';
    if (mb_strlen(trim($comment)) > 2000) $errors['comment'] = 'Review text cannot exceed 2000 characters.';
    return $errors;
}

function validateBookingData(array $input): array
{
    require_once __DIR__ . '/booking_helpers.php';
    $errors = [];
    if (!bookingDateTimeIsValid((string)($input['scheduled_date'] ?? ''), (string)($input['scheduled_time'] ?? ''))) {
        $errors['schedule'] = 'Choose a valid upcoming date and time within the next 90 days.';
    }
    if (mb_strlen(trim((string)($input['notes'] ?? ''))) > 3000) $errors['notes'] = 'Appointment notes cannot exceed 3000 characters.';
    return $errors;
}

function validatePassword(string $password): bool
{
    return strlen($password) >= 8 && (bool)preg_match('/[A-Z]/', $password)
        && (bool)preg_match('/[a-z]/', $password) && (bool)preg_match('/[0-9]/', $password)
        && (bool)preg_match('/[^A-Za-z0-9]/', $password);
}

/** Return field-keyed validation messages so forms can present consistent feedback. */
function validateRegistration(array $input): array
{
    $errors = [];
    $name = trim((string)($input['full_name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirmation = (string)($input['confirm_password'] ?? '');
    $role = (string)($input['role'] ?? '');

    if (!validateName($name)) {
        $errors['full_name'] = 'Enter a name using letters, spaces, apostrophes, periods, or hyphens (up to 120 characters).';
    }
    if (!validateEmail($email)) {
        $errors['email'] = 'Enter a valid email address (up to 190 characters).';
    }
    if (!validatePassword($password)) {
        $errors['password'] = 'Use at least 8 characters with uppercase, lowercase, a number, and a special character.';
    }
    if (!hash_equals($password, $confirmation)) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }
    if (!in_array($role, ['customer', 'provider'], true)) {
        $errors['role'] = 'Choose a valid account type.';
    }

    return $errors;
}
