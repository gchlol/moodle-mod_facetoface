<?php
defined('MOODLE_INTERNAL') || die();

class attendance_sheet_settings {
    protected $configitems;
    protected $data;

    public function __construct() {
        // Example configuration items.
        $this->configitems = [
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
        $this->data = [
            'uniqid'      => uniqid(),
            'header_text' => get_string('column', 'facetoface'),
            'empty_table' => empty($this->configitems),
            'config_items'=> $this->configitems,
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
        $output .= $OUTPUT->render_from_template('mod_courselist/course_config_table', $this->data);

        // Append inline JavaScript for deletion and drag-and-drop behavior.
        $output .= '<script>
            // Minimal inline JavaScript to add deletion and drag-and-drop behavior.
            document.addEventListener("DOMContentLoaded", function() {
                const table = document.querySelector("[data-course-config-table]");
                const tbody = table.querySelector("[data-config-items]");

                // --- Handle Row Deletion ---
                tbody.addEventListener("click", function(e) {
                    if (e.target.closest("[data-remove-row]")) {
                        e.preventDefault();
                        const row = e.target.closest("[data-course-config-item]");
                        if (row) {
                            row.remove();
                        }
                        // If no rows remain, show the "empty" row.
                        if (!tbody.querySelector("[data-course-config-item]")) {
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
                    document.querySelectorAll("[data-course-config-item]").forEach(function(row) {
                        row.classList.remove("over", "dragging");
                    });
                }

                // Set draggable attribute and add drag events on each row.
                tbody.querySelectorAll("[data-course-config-item]").forEach(function(row) {
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
