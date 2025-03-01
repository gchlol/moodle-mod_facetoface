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

        // 1) Render the table using the mustache template.
        $output .= $OUTPUT->render_from_template('mod_facetoface/attendance_sheet_config_table', $this->data);

        // 2) Append inline JavaScript for deletion, drag-and-drop, AND the new dropdown logic.
        $output .= '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const table = document.querySelector("[data-attendance-sheet-config-table]");
            const tbody = table.querySelector("[data-attendance-sheet-config-items]");
    
            // === Row Deletion (existing code) ===
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
    
            // === Drag and Drop (existing code) ===
            let dragSrc = null;
            function handleDragStart(e) {
                dragSrc = this;
                e.dataTransfer.effectAllowed = "move";
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
            function bindDragEvents(row) {
                row.setAttribute("draggable", "true");
                row.addEventListener("dragstart", handleDragStart, false);
                row.addEventListener("dragenter", handleDragEnter, false);
                row.addEventListener("dragover", handleDragOver, false);
                row.addEventListener("dragleave", handleDragLeave, false);
                row.addEventListener("drop", handleDrop, false);
                row.addEventListener("dragend", handleDragEnd, false);
            }
            tbody.querySelectorAll("[data-attendance-sheet-config-item]").forEach(function(row) {
                bindDragEvents(row);
            });
    
            // === NEW: Handle adding rows from the dropdown in the 3rd column ===
            const addRowDropdown = table.querySelector("[data-attendance-sheet-config-add-row]");
            if (addRowDropdown) {
                addRowDropdown.addEventListener("change", function(e) {
                    const selectedValue = e.target.value;
                    if (!selectedValue) {
                        return;
                    }
                    // Reset the dropdown so user can add another item if needed
                    e.target.value = "";
    
                    // Hide the empty row if it is visible
                    const emptyRow = tbody.querySelector(".empty");
                    if (emptyRow) {
                        emptyRow.hidden = true;
                    }
    
                    // Create a new row
                    const tr = document.createElement("tr");
                    tr.setAttribute("data-attendance-sheet-config-item", "");
                    // Example ID for the row
                    tr.setAttribute("data-value", Date.now());
    
                    // --- First column (the label) ---
                    const td1 = document.createElement("td");
                    // If you want the hidden input + drag handle:
                    const hiddenInput = document.createElement("input");
                    hiddenInput.name = "item_ids[]";
                    hiddenInput.type = "hidden";
                    hiddenInput.value = Date.now();
                    td1.appendChild(hiddenInput);
    
                    // Just display the selectedValue
                    // (In your real code, you can add the drag handle partial if needed)
                    td1.appendChild(document.createTextNode(selectedValue));
    
                    // --- Second column ---
                    const td2 = document.createElement("td");
                    if (["Name","Payroll","Email","Signature"].includes(selectedValue)) {
                        // If in [Name, Payroll, Email, Signature], second column is empty
                        td2.textContent = "";
                    } else {
                        // If in [Header Only, Header and Row], second column is a textbox
                        const input = document.createElement("input");
                        input.type = "text";
                        td2.appendChild(input);
                    }
    
                    // --- Third column: the "action" column ---
                    const tdAction = document.createElement("td");
                    tdAction.classList.add("action-column");
    
                    // Add a delete link
                    const deleteLink = document.createElement("a");
                    deleteLink.href = "#";
                    deleteLink.setAttribute("data-attendance-sheet-config-remove-row", "");
                    deleteLink.innerHTML = "Delete";
                    tdAction.appendChild(deleteLink);
    
                    // Append the cells to the new row
                    tr.appendChild(td1);
                    tr.appendChild(td2);
                    tr.appendChild(tdAction);
    
                    // Insert into the table body
                    tbody.appendChild(tr);
    
                    // Re-bind drag events for the newly created row
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
