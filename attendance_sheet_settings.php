<?php
//global $PAGE;
//require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
//$PAGE->set_url('/mod/facetoface/attendance_sheet_settings.php');
//$PAGE->set_context(context_system::instance());
//$PAGE->set_title(get_string('facetoface', 'mod_courselist'));
//$PAGE->set_heading(get_string('pluginname', 'mod_courselist'));
//$PAGE->set_title('Test dynamic table');
//$PAGE->set_heading('Test dynamic table');

// Example configuration items.
$config_items = [
    [
        'id'        => 100,
        'label'     => 'Example Course #1',
        'movetitle' => 'Move Example Course #1'
    ],
    [
        'id'        => 102,
        'label'     => 'Example Course #2',
        'movetitle' => 'Move Example Course #2'
    ]
];

// Prepare context data for the mustache template.
$data = [
    'uniqid'      => uniqid(),
    'header_text' => get_string('column', 'facetoface'),
//    'header_text' => 'Test header text',
    'empty_table' => empty($config_items),
    'config_items'=> $config_items,
];

echo $OUTPUT->header();
// Render the table using the mustache template.
echo $OUTPUT->render_from_template('mod_courselist/course_config_table', $data);
?>

    <script>
        // Minimal inline JavaScript to add deletion and drag-and-drop behavior.
        document.addEventListener('DOMContentLoaded', function() {
            const table = document.querySelector('[data-course-config-table]');
            const tbody = table.querySelector('[data-config-items]');

            // --- Handle Row Deletion ---
            tbody.addEventListener('click', function(e) {
                if (e.target.closest('[data-remove-row]')) {
                    e.preventDefault();
                    const row = e.target.closest('[data-course-config-item]');
                    if (row) {
                        row.remove();
                    }
                    // If no rows remain, show the "empty" row.
                    if (!tbody.querySelector('[data-course-config-item]')) {
                        const emptyRow = tbody.querySelector('.empty');
                        if (emptyRow) {
                            emptyRow.hidden = false;
                        }
                    }
                }
            });

            // --- Enable Drag and Drop ---
            let dragSrc = null;

            function handleDragStart(e) {
                dragSrc = this;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', null); // required for Firefox
                this.classList.add('dragging');
            }

            function handleDragOver(e) {
                if (e.preventDefault) { e.preventDefault(); }
                e.dataTransfer.dropEffect = 'move';
                return false;
            }

            function handleDragEnter() { this.classList.add('over'); }
            function handleDragLeave() { this.classList.remove('over'); }

            function handleDrop(e) {
                if (e.stopPropagation) { e.stopPropagation(); }
                if (dragSrc !== this) {
                    // Swap innerHTML of dragged and dropped rows.
                    const srcHTML = dragSrc.innerHTML;
                    dragSrc.innerHTML = this.innerHTML;
                    this.innerHTML = srcHTML;
                }
                return false;
            }

            function handleDragEnd() {
                document.querySelectorAll('[data-course-config-item]').forEach(function(row) {
                    row.classList.remove('over', 'dragging');
                });
            }

            // Set draggable attribute and add drag events on each row.
            tbody.querySelectorAll('[data-course-config-item]').forEach(function(row) {
                row.setAttribute('draggable', 'true');
                row.addEventListener('dragstart', handleDragStart, false);
                row.addEventListener('dragenter', handleDragEnter, false);
                row.addEventListener('dragover', handleDragOver, false);
                row.addEventListener('dragleave', handleDragLeave, false);
                row.addEventListener('drop', handleDrop, false);
                row.addEventListener('dragend', handleDragEnd, false);
            });
        });
    </script>

<?php
echo $OUTPUT->footer();
