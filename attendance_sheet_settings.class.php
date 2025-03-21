<?php
/**
 * Sets up and renders the attendance sheet configuration.
 *
 * @package    mod_facetoface
 * @copyright  2025 Gold Coast Health
 * @author     Yucheng Zhu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
global $CFG;

use mod_facetoface\enum\attendance_column;

require_once("$CFG->dirroot/mod/facetoface/classes/data/attendance_sheet_io.php");

defined('MOODLE_INTERNAL') || die();

class attendance_sheet_settings {
    protected $attendanceitems;
    protected $data;
    protected $jsonfile;

    public function __construct(int $instanceid) {
        global $CFG, $PAGE, $DB;

        $this->instanceid = $instanceid;

        $PAGE->requires->css(new moodle_url('/mod/facetoface/style/attendance_sheet_config_styles.css'));

        $jsonfile = get_attendance_config_file($CFG, $this->instanceid);

        $instance = $DB->get_record('facetoface', [ 'id' => $this->instanceid ]);

        $this->attendanceitems = return_updated_json_content($jsonfile, $instance);

        // Map the attendance_sheet table GUI header names to their corresponding enum values.
        $attendance_columns_enums_to_names = attendance_column::map_attendance_columns_enums_to_names();

        // Process each attendance item.
        foreach ($this->attendanceitems as &$item) {
            if (isset($item['column'])) {
                $column = $item['column'];

                if (is_int($column) && isset($attendance_columns_enums_to_names[$column])) {
                    $column = $attendance_columns_enums_to_names[$column];
                    $item['column'] = $column;
                }

                $item['editable'] = false;
                if ($column === 'Custom Column') {
                    $item['editable'] = true;
                    $item['value'] = isset($item['value']) ? $item['value'] : '';
                }
            }
        }

        // Prepare context data for the mustache template.
        $this->data = [
            'uniqid'             => uniqid(),
            'header_text_column' => get_string('headertextcolumn', 'facetoface'),
            'header_text_value'  => get_string('headertextrowvalue', 'facetoface'),
            'empty_table'        => empty($this->attendanceitems),
            'attendance_items'   => $this->attendanceitems,
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
        $tablehtml = $OUTPUT->render_from_template('mod_facetoface/attendance_sheet_config_table', $this->data);
        // Wrap the table with a container that includes a left-hand label "Column config".
        $output .= '<div class="attendance-sheet-config__wrapper" style="display: flex; align-items: flex-start;">';
        $output .= '<div class="edw-form-label d-inline">Column config</div>';
        $output .= '<div class="attendance-sheet-config__table">' . $tablehtml . '</div>';
        $output .= '</div>';

        // Prepare the exact HTML for the Moodle drag handle partial.
        $draghandlehtml = $OUTPUT->render_from_template('core/drag_handle', []);

        // Prepare the delete icon + alt text.
        $removerowicon = $OUTPUT->pix_icon('t/delete', get_string('modform:removerow', 'facetoface'), 'core');
        $removerowtext = '';

        // Append inline JavaScript for row deletion, drag-and-drop, adding new rows,
        // and saving config via AJAX. All variables outside functions are passed as parameters.
        $output .= '<script>
            document.addEventListener("DOMContentLoaded", () => {
                let tableElement = document.querySelector("[data-attendance-sheet-config-table]");
                if (!tableElement) {
                    return;
                }
                let tbodyElement = tableElement.querySelector("[data-attendance-sheet-config-items]");
                if (!tbodyElement) {
                    return;
                }
                let addRowDropdown = tableElement.querySelector("[data-attendance-sheet-config-add-row]");
                let saveButton = document.getElementById("save-config");
                let cancelButton = document.getElementById("cancel-config");

                let params = {
                    tableElement: tableElement,
                    tbodyElement: tbodyElement,
                    addRowDropdown: addRowDropdown,
                    saveButton: saveButton,
                    cancelButton: cancelButton,
                    dragHandleHtml: ' . json_encode($draghandlehtml) . ',
                    removeRowIconHtml: ' . json_encode($removerowicon) . ',
                    removeRowText: ' . json_encode($removerowtext) . ',
                    saveConfigUrl: "/mod/facetoface/save_config.php",
                    sesskey: (typeof M !== "undefined" && M.cfg && M.cfg.sesskey) ? M.cfg.sesskey : "",
                    instanceid: ' . json_encode($this->instanceid) . '
                };

                new AttendanceSheetConfig(params).init();
            });

            class AttendanceSheetConfig {
                constructor(params) {
                    this.tableElement = params.tableElement;
                    this.tbodyElement = params.tbodyElement;
                    this.addRowDropdown = params.addRowDropdown;
                    this.saveButton = params.saveButton;
                    this.cancelButton = params.cancelButton;
                    this.dragHandleHtml = params.dragHandleHtml;
                    this.removeRowIconHtml = params.removeRowIconHtml;
                    this.removeRowText = params.removeRowText;
                    this.saveConfigUrl = params.saveConfigUrl;
                    this.sesskey = params.sesskey;
                    this.instanceid = params.instanceid;
                }

                init() {
                    this.initRowDeletion();
                    this.initDragAndDrop();
                    if (this.addRowDropdown) {
                        this.initAddRowDropdown();
                    }
                    if (this.saveButton) {
                        this.initSaveButton();
                    }
                    if (this.cancelButton) {
                        this.initCancelButton();
                    }
                }

                initRowDeletion() {
                    this.tbodyElement.addEventListener("click", (e) => {
                        let removeRowTrigger = e.target.closest("[data-attendance-sheet-config-remove-row]");
                        if (removeRowTrigger) {
                            e.preventDefault();
                            let row = removeRowTrigger.closest("[data-attendance-sheet-config-item]");
                            if (row) {
                                row.remove();
                            }
                            if (!this.tbodyElement.querySelector("[data-attendance-sheet-config-item]")) {
                                let emptyRow = this.tbodyElement.querySelector(".empty");
                                if (emptyRow) {
                                    emptyRow.hidden = false;
                                }
                            }
                        }
                    });
                }

                initDragAndDrop() {
                    let dragSrc = null;

                    const handleDragStart = (e) => {
                        dragSrc = e.currentTarget;
                        e.dataTransfer.effectAllowed = "move";
                        e.dataTransfer.setData("text/plain", null);
                        e.currentTarget.classList.add("dragging");
                    };

                    const handleDragOver = (e) => {
                        if (e.preventDefault) { e.preventDefault(); }
                        e.dataTransfer.dropEffect = "move";
                        return false;
                    };

                    const handleDragEnter = (e) => {
                        e.currentTarget.classList.add("over");
                    };

                    const handleDragLeave = (e) => {
                        e.currentTarget.classList.remove("over");
                    };

                    const handleDrop = (e) => {
                        if (e.stopPropagation) { e.stopPropagation(); }
                        if (dragSrc !== e.currentTarget) {
                            let srcHTML = dragSrc.innerHTML;
                            dragSrc.innerHTML = e.currentTarget.innerHTML;
                            e.currentTarget.innerHTML = srcHTML;
                        }
                        return false;
                    };

                    const handleDragEnd = () => {
                        let rows = this.tbodyElement.querySelectorAll("[data-attendance-sheet-config-item]");
                        rows.forEach(row => row.classList.remove("over", "dragging"));
                    };

                    const bindDragEvents = (row) => {
                        row.setAttribute("draggable", "true");
                        row.addEventListener("dragstart", handleDragStart, false);
                        row.addEventListener("dragenter", handleDragEnter, false);
                        row.addEventListener("dragover", handleDragOver, false);
                        row.addEventListener("dragleave", handleDragLeave, false);
                        row.addEventListener("drop", handleDrop, false);
                        row.addEventListener("dragend", handleDragEnd, false);
                    };

                    let rows = this.tbodyElement.querySelectorAll("[data-attendance-sheet-config-item]");
                    rows.forEach(row => bindDragEvents(row));
                    this.bindDragEvents = bindDragEvents;
                }

                initAddRowDropdown() {
                
                    this.addRowDropdown.addEventListener("change", (e) => {
                        let selectedValue = e.target.value;
                        if (!selectedValue) {
                            return;
                        }
                        e.target.value = "";
                        let emptyRow = this.tbodyElement.querySelector(".empty");
                        if (emptyRow) {
                            emptyRow.hidden = true;
                        }
                        let rowId = Date.now();
                        let tr = document.createElement("tr");
                        tr.setAttribute("data-attendance-sheet-config-item", "");
                        tr.setAttribute("data-value", rowId);
                        if (selectedValue === "Custom Column") {
                            tr.setAttribute("data-changeable", selectedValue);
                        }

                        let td1 = document.createElement("td");
                        if (selectedValue === "Custom Column") {
                            td1.innerHTML =
                                \' <input name="item_ids[]" type="hidden" value="\' + rowId + \'" /> \' +
                                \' <span class="attendance-sheet-config__table__cell--first__input"> \' +
                                    this.dragHandleHtml +
                                    \' <input type="text" name="column_names[]" placeholder="Enter column name" class="form-control" /> \' +
                                \' </span>\';
                        } else {
                            td1.innerHTML =
                                \'<input name="item_ids[]" type="hidden" value="\' + rowId + \'" />\' +
                                this.dragHandleHtml +
                                selectedValue;
                        }

                        let td2 = document.createElement("td");
                        if (selectedValue === "Custom Column") {
                            td2.innerHTML = \'<input type="text" name="header_values[]" placeholder="Enter default value" class="form-control" />\';
                        } else if (["Name", "Payroll", "Email", "Org Unit", "Position", "Stream", "Paypoint"].indexOf(selectedValue) !== -1) {
                            td2.innerHTML = "";
                        } else {
                            td2.innerHTML = \'<input type="text" name="header_values[]" />\';
                        }

                        let td3 = document.createElement("td");
                        td3.classList.add("action-column");
                        td3.innerHTML =
                            \'<a href="#" data-attendance-sheet-config-remove-row data-remove-value="\' + rowId + \'">\' +
                            this.removeRowIconHtml + " " + this.removeRowText +
                            \'</a>\';
                        tr.appendChild(td1);
                        tr.appendChild(td2);
                        tr.appendChild(td3);
                        this.tbodyElement.appendChild(tr);
                        if (typeof this.bindDragEvents === "function") {
                            this.bindDragEvents(tr);
                        }
                    });
                }

                initSaveButton() {
                    this.saveButton.addEventListener("click", (e) => {
                        let configData = [];
                        let rows = this.tbodyElement.querySelectorAll("[data-attendance-sheet-config-item]");
                        rows.forEach(row => {
                            let cells = row.querySelectorAll("td");
                            
                            let columnName = "";
                            let inputCol = cells[0].querySelector("input[name=\'column_names[]\']");
                            if (inputCol) {
                                columnName = inputCol.value.trim();
                            } else {
                                columnName = cells[0].textContent.trim();
                            }
                            
                            let defaultValue = "";
                            let input = cells[1].querySelector("input");
                            if (input) {
                                defaultValue = input.value.trim();
                            } else {
                                defaultValue = cells[1].textContent.trim();
                            }
                            
                            let item = {
                                column: columnName, 
                                value: defaultValue
                            };
                            configData.push(item);
                        });
                        let xhr = new XMLHttpRequest();
                        xhr.open("POST", this.saveConfigUrl, false);
                        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                        xhr.onreadystatechange = () => {
                            if (xhr.readyState === 4) {
                                if (xhr.status === 200) {
                                    alert("Configuration saved successfully.");
                                } else {
                                    alert("Failed to save configuration. Status Code: " + xhr.status);
                                }
                            }
                        };
                        let paramsStr = "sesskey=" + encodeURIComponent(this.sesskey) +
                                        "&instanceid=" + encodeURIComponent(this.instanceid) +
                                        "&config_data=" + encodeURIComponent(JSON.stringify(configData));
                        xhr.send(paramsStr);
                    });
                }

                initCancelButton() {
                    this.cancelButton.addEventListener("click", (e) => {
                        e.preventDefault();
                        if (confirm("Do you want to cancel the change? All unsaved items in the text field will be lost.")) {
                            location.reload();
                        }
                    });
                }
            }
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
?>
