<?php
defined('MOODLE_INTERNAL') || die();

class attendance_sheet_settings {
    protected $attendanceitems;
    protected $data;

    public function __construct() {
        // Example attendance sheet items.
        $this->attendanceitems = [
            [
                'id'     => 0,
                'labels' => [
                    [
                        'value' => 'Name', // corresponds to "Name"
                        'first' => true    // flag to include the hidden input & drag handle
                    ],
                    [
                        'value' => ''      // Default value for 'Name'
                    ]
                ]
            ],
            [
                'id'     => 1,
                'labels' => [
                    [
                        'value' => 'Pass/Fail', // corresponds to "Pass/Fail"
                        'first' => true         // flag to include the hidden input & drag handle
                    ],
                    [
                        'value' => 'Pass/Fail'  // Default value for "Pass/Fail"
                    ]
                ]
            ]
        ];

        // Prepare context data for the mustache template.
        $this->data = [
            'uniqid'           => uniqid(),
            'header_text_column'      => get_string('headertextcolumn', 'facetoface'),
            'header_text_value'      => get_string('headertextdefaultvalue', 'facetoface'),
            'empty_table'      => empty($this->attendanceitems),
            'attendance_items' => $this->attendanceitems,
        ];
    }

    /**
     * Renders the attendance sheet settings table and associated JavaScript.
     *
     * @return string The rendered HTML output.
     */
    public function render() {
        global $OUTPUT;
        $output = '';

        // Render the table using the mustache template.
        $output .= $OUTPUT->render_from_template('mod_facetoface/attendance_sheet_config_table', $this->data);

        // Append inline JavaScript for deletion and drag-and-drop behavior.
        $output .= '<script>
            // Minimal inline JavaScript to add deletion and drag-and-drop behavior.
            document.addEventListener("DOMContentLoaded", function() {
                const table = document.querySelector("[data-attendance-sheet-config-table]");
                const tbody = table.querySelector("[data-attendance-sheet-config-items]");

                // --- Handle Row Deletion ---
                tbody.addEventListener("click", function(e) {
                    if (e.target.closest("[data-attendance-sheet-config-remove-row]")) {
                        e.preventDefault();
                        const row = e.target.closest("[data-attendance-sheet-config-item]");
                        if (row) {
                            row.remove();
                        }
                        // If no rows remain, show the "empty" row.
                        if (!tbody.querySelector("[data-attendance-sheet-config-item]")) {
                            const emptyRow = tbody.querySelector(".empty");
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
                    e.dataTransfer.effectAllowed = "move";
                    e.dataTransfer.setData("text/plain", null); // required for Firefox
                    this.classList.add("dragging");
                }

                function handleDragOver(e) {
                    if (e.preventDefault) { e.preventDefault(); }
                    e.dataTransfer.dropEffect = "move";
                    return false;
                }

                function handleDragEnter() { this.classList.add("over"); }
                function handleDragLeave() { this.classList.remove("over"); }

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
                    document.querySelectorAll("[data-attendance-sheet-config-item]").forEach(function(row) {
                        row.classList.remove("over", "dragging");
                    });
                }

                // Set draggable attribute and add drag events on each row.
                tbody.querySelectorAll("[data-attendance-sheet-config-item]").forEach(function(row) {
                    row.setAttribute("draggable", "true");
                    row.addEventListener("dragstart", handleDragStart, false);
                    row.addEventListener("dragenter", handleDragEnter, false);
                    row.addEventListener("dragover", handleDragOver, false);
                    row.addEventListener("dragleave", handleDragLeave, false);
                    row.addEventListener("drop", handleDrop, false);
                    row.addEventListener("dragend", handleDragEnd, false);
                });
            });
        </script>';

        return $output;
    }

    /**
     * Inserts the rendered attendance sheet settings into the given Moodle form.
     *
     * @param moodleform $mform The Moodle form instance.
     */
    public function add_to_form(&$mform) {
        $mform->addElement('html', $this->render());
    }
}
