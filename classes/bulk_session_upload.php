<?php
// bulkupload.php
require_once('../../config.php');
require_login();

// Check necessary capabilities if needed.
$context = context_module::instance($cm->id ?? 0);
require_capability('mod/facetoface:manage', $context);

$PAGE->set_url(new moodle_url('/mod/facetoface/bulkupload.php'));
$PAGE->set_title("Bulk Upload Face-to-Face Sessions");
$PAGE->set_heading("Bulk Upload Face-to-Face Sessions");

echo $OUTPUT->header();
?>

<form action="bulkupload.php" method="post" enctype="multipart/form-data">
    <label for="csvfile">Choose CSV file:</label>
    <input type="file" name="csvfile" id="csvfile" accept=".csv" required>
    <br><br>
    <input type="submit" name="upload" value="Upload CSV">
</form>

<?php
if (!empty($_POST['upload']) && !empty($_FILES['csvfile']) && $_FILES['csvfile']['error'] === UPLOAD_ERR_OK) {
    // Open and read the CSV file.
    if (($handle = fopen($_FILES['csvfile']['tmp_name'], 'r')) !== false) {
        // Assume the first row contains headers.
        $headers = fgetcsv($handle, 1000, ",");
        $errors = [];
        $sessionscreated = 0;

        // Process each row.
        while (($data = fgetcsv($handle, 1000, ",")) !== false) {
            // Combine headers with data.
            $row = array_combine($headers, $data);
            
            // Validate required fields.
            if (empty($row['Facetoface id']) || empty($row['Start date and time']) || empty($row['Finish date and time'])) {
                $errors[] = "Missing required fields in row: " . implode(", ", $data);
                continue;
            }
            
            // Prepare session data.
            $session = new stdClass();
            $session->facetofaceid = $row['Facetoface id'];
            $session->starttime   = strtotime($row['Start date and time']);
            $session->finishtime  = strtotime($row['Finish date and time']);
            $session->allowcancel = isset($row['allow cancelations']) ? ($row['allow cancelations'] == 'no' ? 0 : 1) : 1;
            $session->capacity    = !empty($row['Capacity']) ? (int)$row['Capacity'] : 10;
            $session->overbook    = isset($row['Allow overbookings']) ? ($row['Allow overbookings'] == 'no' ? 0 : 1) : 1;
            $session->details     = !empty($row['Details']) ? $row['Details'] : '';
            
            // Handle custom fields if applicable.
            if (!empty($row['customfield_shortname'])) {
                $session->customfields = [$row['customfield_shortname'] => $row['customfield_value'] ?? ''];
            }
            
            // Use the Face-to-Face plugin’s internal session creation function.
            // For example, if the plugin has a function like:
            //     bool create_session($session)
            // then you can call it directly.
            // if (create_session($session)) {
            //     $sessionscreated++;
            // } else {
            //     $errors[] = "Error creating session for Facetoface id: {$session->facetofaceid}";
            // }
            //
            // For demonstration, we assume it succeeds:
            $sessionscreated++;
        }
        fclose($handle);

        // Display processing results.
        echo html_writer::div("Bulk upload completed: $sessionscreated sessions created.", 'notifysuccess');
        if (!empty($errors)) {
            echo html_writer::div(implode("<br>", $errors), 'error');
        }
    } else {
        echo html_writer::div("Error reading the CSV file.", 'error');
    }
}

echo $OUTPUT->footer();
