<?php

defined('MOODLE_INTERNAL') || die();

class attendance_sheet_settings {
    protected $attendanceitems;
    protected $data;

    public function __construct() {
        // Example attendance sheet items that will appear initially.
        $this->attendanceitems = [
            [
                'id'     => 0,
                'labels' => [
                    [
                        'value' => 'Name', // "Name" in the first column
                        'first' => true    // indicates we include the hidden input + drag handle
                    ],
                    [
                        'value' => ''      // second column is empty by default
                    ]
                ]
            ],
            [
                'id'     => 1,
                'labels' => [
                    [
                        'value' => 'Pass/Fail', // "Pass/Fail" in the first column
                        'first' => true
                    ],
                    [
                        'value' => 'Pass/Fail'  // second column has "Pass/Fail"
                    ]
                ]
            ]
        ];

        // Prepare context data for the mustache template.
        $this->data = [
            'uniqid'                 => uniqid(),
            'header_text_column'     => get_string('headertextcolumn', 'facetoface'),
            'header_text_value'      => get_string('headertextdefaultvalue', 'facetoface'),
            'empty_table'            => empty($this->attendanceitems),
            'attendance_items'       => $this->attendanceitems,
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

        // Render the table using the Mustache template.
        $output .= $OUTPUT->render_from_template('mod_facetoface/attendance_sheet_config_table', $this->data);

        // Prepare the exact HTML for the Moodle drag handle partial.
        $draghandlehtml = $OUTPUT->render_from_template('core/drag_handle', []);

        // Prepare the delete icon + alt text (like {{#pix}} t/delete, core, ...).
        $removerowicon = $OUTPUT->pix_icon('t/delete', get_string('modform:removerow', 'facetoface'), 'core');
        $removerowtext = '';

        // Append inline JavaScript for row deletion, drag-and-drop, and adding new rows from the dropdown.
        $output .= '<script>
            document.addEventListener("DOMContentLoaded", function() {
                var table = document.querySelector("[data-attendance-sheet-config-table]");
                if (!table) {
                    return;
                }
                var tbody = table.querySelector("[data-attendance-sheet-config-items]");
                if (!tbody) {
                    return;
                }

                // Provide the drag handle + delete icon HTML to the JS code.
                var dragHandleHtml = ' . json_encode($draghandlehtml) . ';
                var removeRowIconHtml = ' . json_encode($removerowicon) . ';
                var removeRowText = ' . json_encode($removerowtext) . ';

                // === Row Deletion ===
                tbody.addEventListener("click", function(e) {
                    if (e.target.closest("[data-attendance-sheet-config-remove-row]")) {
                        e.preventDefault();
                        var row = e.target.closest("[data-attendance-sheet-config-item]");
                        if (row) {
                            row.remove();
                        }
                        // If no rows remain, show the "empty" row.
                        if (!tbody.querySelector("[data-attendance-sheet-config-item]")) {
                            var emptyRow = tbody.querySelector(".empty");
                            if (emptyRow) {
                                emptyRow.hidden = false;
                            }
                        }
                    }
                });

                // === Drag and Drop ===
                var dragSrc = null;

                function handleDragStart(e) {
                    dragSrc = this;
                    e.dataTransfer.effectAllowed = "move";
                    // Required for Firefox.
                    e.dataTransfer.setData("text/plain", null);
                    this.classList.add("dragging");
                }

                function handleDragOver(e) {
                    if (e.preventDefault) { e.preventDefault(); }
                    e.dataTransfer.dropEffect = "move";
                    return false;
                }

                function handleDragEnter() {
                    this.classList.add("over");
                }

                function handleDragLeave() {
                    this.classList.remove("over");
                }

                function handleDrop(e) {
                    if (e.stopPropagation) { e.stopPropagation(); }
                    if (dragSrc !== this) {
                        // Swap the entire innerHTML of the dragged and dropped rows.
                        var srcHTML = dragSrc.innerHTML;
                        dragSrc.innerHTML = this.innerHTML;
                        this.innerHTML = srcHTML;
                    }
                    return false;
                }

                function handleDragEnd() {
                    // Remove highlighting from all rows.
                    document.querySelectorAll("[data-attendance-sheet-config-item]").forEach(function(row) {
                        row.classList.remove("over", "dragging");
                    });
                }

                // Bind drag events to a row.
                function bindDragEvents(row) {
                    row.setAttribute("draggable", "true");
                    row.addEventListener("dragstart", handleDragStart, false);
                    row.addEventListener("dragenter", handleDragEnter, false);
                    row.addEventListener("dragover", handleDragOver, false);
                    row.addEventListener("dragleave", handleDragLeave, false);
                    row.addEventListener("drop", handleDrop, false);
                    row.addEventListener("dragend", handleDragEnd, false);
                }

                // Initially bind drag events for existing rows.
                tbody.querySelectorAll("[data-attendance-sheet-config-item]").forEach(function(row) {
                    bindDragEvents(row);
                });

                // === Dropdown for Adding Rows ===
                var addRowDropdown = table.querySelector("[data-attendance-sheet-config-add-row]");
                if (addRowDropdown) {
                    addRowDropdown.addEventListener("change", function(e) {
                        var selectedValue = e.target.value;
                        if (!selectedValue) {
                            return;
                        }
                        // Reset dropdown to its default option.
                        e.target.value = "";

                        // Hide the empty row if visible.
                        var emptyRow = tbody.querySelector(".empty");
                        if (emptyRow) {
                            emptyRow.hidden = true;
                        }

                        // Create a unique ID for the new row.
                        var rowId = Date.now();

                        // Create a new <tr>.
                        var tr = document.createElement("tr");
                        tr.setAttribute("data-attendance-sheet-config-item", "");
                        tr.setAttribute("data-value", rowId);

                        // --- First column (drag handle + hidden input + label) ---
                        var td1 = document.createElement("td");
                        td1.innerHTML =
                            \'<input name="item_ids[]" type="hidden" value="\' + rowId + \'" />\' +
                            dragHandleHtml + // same as {{> core/drag_handle}}
                            selectedValue;   // the label

                        // --- Second column (depends on selection) ---
                        var td2 = document.createElement("td");
                        if (["Name","Payroll","Email","Signature"].indexOf(selectedValue) !== -1) {
                            // For these, second column is empty
                            td2.innerHTML = "";
                        } else {
                            // For "Header Only" / "Header and Row", we add a text input
                            td2.innerHTML = \'<input type="text" name="header_values[]" />\';
                        }

                        // --- Third column (delete link) ---
                        var td3 = document.createElement("td");
                        td3.classList.add("action-column");
                        td3.innerHTML =
                            \'<a href="#" data-attendance-sheet-config-remove-row data-remove-value="\' + rowId + \'">\' +
                            removeRowIconHtml +  // same icon as {{#pix}} t/delete ...
                            " " +
                            removeRowText +
                            \'</a>\';

                        // Append <td> cells to <tr>.
                        tr.appendChild(td1);
                        tr.appendChild(td2);
                        tr.appendChild(td3);

                        // Add the new row to the table body.
                        tbody.appendChild(tr);

                        // Bind drag-and-drop events to the new row.
                        bindDragEvents(tr);
                    });
                }
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