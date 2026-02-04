// Initial Render of Countermeasures
document.addEventListener('DOMContentLoaded', () => {
    if (typeof initialCM !== 'undefined') {
        renderTable(initialCM);
    }
    // Create modal dynamically if it doesn't exist
    createStatusModal();
});

// --- CREATE STATUS MODAL DYNAMICALLY ---
function createStatusModal() {
    if (document.getElementById('statusModal')) return;

    const modal = document.createElement('div');
    modal.id = 'statusModal';
    modal.style.cssText = 'display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); justify-content:center; align-items:center; z-index:1000;';

    modal.innerHTML = `
        <div style="background:white; padding:30px; border-radius:15px; text-align:center; max-width:400px; width:90%;">
            <h3 id="modalTitle" style="margin-bottom:20px;">Update Status</h3>
            <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:20px;">
                <button onclick="selectStatus('green')" style="background:#28a745; color:white; padding:15px; border:none; border-radius:8px; cursor:pointer; font-size:16px;">
                    ✓ موافق<br><small>Met / Conforme</small>
                </button>
                <button onclick="selectStatus('orange')" style="background:#fd7e14; color:white; padding:15px; border:none; border-radius:8px; cursor:pointer; font-size:16px;">
                    ⚠️ إجراء<br><small>Action Required</small>
                </button>
                <button onclick="selectStatus('red')" style="background:#dc3545; color:white; padding:15px; border:none; border-radius:8px; cursor:pointer; font-size:16px;">
                    ✗ خطر<br><small>Missed / Accident</small>
                </button>
                <button onclick="selectStatus('blue')" style="background:#007bff; color:white; padding:15px; border:none; border-radius:8px; cursor:pointer; font-size:16px;">
                    📅 عطلة<br><small>Holiday / N.A.</small>
                </button>
            </div>
            <button onclick="selectStatus('gray')" style="background:#6c757d; color:white; padding:10px 30px; border:none; border-radius:8px; cursor:pointer; margin-bottom:15px;">
                ○ مسح<br><small>Clear</small>
            </button>
            <br>
            <button onclick="closeModal()" style="background:#eee; color:#333; padding:10px 30px; border:none; border-radius:8px; cursor:pointer;">
                إلغاء / Cancel
            </button>
        </div>
    `;

    document.body.appendChild(modal);
}

// --- GLOBAL FUNCTION CALLED BY INDEX.PHP ---
function openDate(category, dateKey, currentStatus) {
    // Parse the date from the dateKey (format: YYYY-M-D)
    const parts = dateKey.split('-');
    const year = parseInt(parts[0]);
    const month = parseInt(parts[1]);
    const day = parseInt(parts[2]);

    openModal(category, day, year, month);
}

// --- MODAL LOGIC FOR DAY SELECTION ---
// --- GLOBAL VARIABLES ---
let currentDay = null;
let currentCategory = null;
let targetYear = null;
let targetMonth = null;
let pendingRedUpdate = null; // Stores {date, category} if Red status is pending issue save

// --- MASTER LOGIC MESSAGES ---
const statusMessages = {
    green: {
        'S': "أؤكد أن اليوم مر **بدون أي حوادث** (Zero Accidents)، وأن جميع العمال التزموا بمعدات الوقاية.",
        'Q': "أؤكد أن الإنتاج اليوم كان **مطابقاً للمواصفات 100%** ولا توجد عيوب تذكر.",
        'D': "أؤكد أننا **حققنا هدف الإنتاج** اليومي (Target Hit) في الوقت المحدد.",
        '5S': "أؤكد أن مكان العمل **نظيف ومرتب** تماماً وفق معايير 5S عند نهاية الدوام.",
        'C': "أؤكد عدم وجود أي **هدر للمواد** أو توقفات مكلفة اليوم."
    },
    orange: {
        'S': "أنت تسجل حالة **خطر وشيك** (Near Miss). يُنصح بشدة إضافة التفاصيل في سجل المشاكل لمنع الحوادث.",
        'Q': "يوجد **عيوب بسيطة** تتطلب إعادة العمل (Rework). يفضل تسجيل المشكلة لمتابعة الجودة.",
        'D': "لم يتم تحقيق الهدف بالكامل (تأخير بسيط). هل تريد **تسجيل السبب** لتفاديه غداً؟",
        '5S': "المكان يحتاج إلى تنظيم. يرجى **كتابة ما يجب فعله** في خانة المشاكل.",
        'C': "يوجد هدر بسيط في الموارد. هل قمت بتحديد السبب؟"
    },
    red: {
        'S': "⚠️ **تنبيه حاد:** حادث شغل! **يجب إلزامياً تسجيل تفاصيل الحادث** الآن لفتح تحقيق.",
        'Q': "⚠️ **توقف:** فشل جودة حرج (Non-Conformity). **النظام يتطلب منك توثيق العيب** قبل المتابعة.",
        'D': "⚠️ **توقف:** توقف الخط أو فشل التسليم. **يجب ذكر سبب التوقف** (Root Cause) إجبارياً.",
        '5S': "⚠️ **تنبيه:** فشل تدقيق 5S (فوضى). **لا يمكن إغلاق اليوم دون تسجيل المخالفات**.",
        'C': "⚠️ **خسارة مادية:** **يجب توثيق قيمة وسبب الخسارة** (مثل كسر آلة) حالاً."
    },
    blue: "هل تؤكد أن المصنع/الخط كان **متوقفاً تماماً** عن العمل اليوم (عطلة رسمية أو توقف مبرمج)؟"
};

function openModal(type, day, currentYear, currentMonth) {
    // Save context globally
    currentDay = day;
    currentCategory = type;
    targetYear = currentYear;
    targetMonth = currentMonth;

    // --- DATE VALIDATION LOGIC ---
    const now = new Date();
    const today = new Date();
    today.setHours(0, 0, 0, 0); // Start of today

    const selectedDate = new Date(currentYear, currentMonth - 1, day);

    // 1. Prevent Future Days
    if (selectedDate > today) {
        Swal.fire({
            icon: 'warning',
            title: 'Future Date / تاريخ مستقبلي',
            text: 'You cannot fill reporting for future days. / لا يمكنك ملء التقارير للأيام المستقبلية.'
        });
        return;
    }

    // 2. Prevent filling TODAY before 7 PM GMT (19:00)
    const isSameDay = selectedDate.getTime() === today.getTime();
    if (isSameDay) {
        const gmtHour = now.getUTCHours();
        if (gmtHour < 19) {
            Swal.fire({
                icon: 'warning',
                title: '⏰ مبكر جداً / Too Early',
                html: `لا يمكن ملء بيانات اليوم إلا بعد <strong>الساعة 7 مساءً</strong> (توقيت غرينيتش).<br><br>
                       You can only fill today's data after <strong>7:00 PM GMT</strong>.<br><br>
                       <small>الوقت الحالي GMT: ${now.getUTCHours()}:${String(now.getUTCMinutes()).padStart(2, '0')}</small>`
            });
            return;
        }
    }

    // 3. Prevent Old Days (> 7 days)
    const diffTime = Math.abs(today - selectedDate);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));

    if (diffDays > 7) {
        Swal.fire({
            icon: 'error',
            title: 'Locked / مقفل',
            text: 'You can only edit data for the last 7 days. / يمكنك فقط تعديل البيانات للأيام السبعة الماضية.'
        });
        return;
    }

    // Update Modal Title (Trilingual)
    const catNames = {
        'S': 'Safety / السلامة',
        'Q': 'Quality / الجودة',
        'D': 'Delivery / التسليم',
        '5S': '5S / التحسين',
        'C': 'Cost / التكلفة'
    };

    const dateStr = `${currentYear}-${currentMonth}-${day}`;
    document.getElementById('modalTitle').innerHTML = `Update <strong>${catNames[type] || type}</strong> <br> <small>${dateStr}</small>`;

    // Show Modal
    document.getElementById('statusModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('statusModal').style.display = 'none';
}

function selectStatus(status) {
    const dateStr = `${targetYear}-${targetMonth}-${currentDay}`;
    const category = currentCategory;
    const msg = statusMessages[status] && statusMessages[status][category]
        ? statusMessages[status][category]
        : statusMessages[status] || "Confirm?"; // Fallback

    closeModal();

    // --- GREEN & BLUE (Direct Save) ---
    if (status === 'green' || status === 'blue') {
        Swal.fire({
            title: 'تأكيد / Confirm',
            html: msg,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '✅ تأكيد وحفظ',
            cancelButtonText: 'إلغاء'
        }).then((result) => {
            if (result.isConfirmed) {
                performStatusUpdate(category, dateStr, status);
            }
        });
    }
    // --- ORANGE (Soft Nudge) ---
    else if (status === 'orange') {
        Swal.fire({
            title: '⚠️ انتباه / Warning',
            html: msg,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '⚠️ سأضيف الملاحظة',
            cancelButtonText: 'حفظ بدون ملاحظة'
        }).then((result) => {
            // Save status regardless
            performStatusUpdate(category, dateStr, status).then(() => {
                if (result.isConfirmed) {
                    addCounterMeasure(dateStr, category); // Prompt to add issue
                }
            });
        });
    }
    // --- RED (Hard Stop) ---
    else if (status === 'red') {
        Swal.fire({
            title: '🚨 توقف / STOP',
            html: msg,
            icon: 'error',
            allowOutsideClick: false,
            confirmButtonColor: '#d33',
            confirmButtonText: '🚨 تسجيل المشكلة (إجباري)'
        }).then(() => {
            // DO NOT SAVE STATUS YET.
            // Open Add Issue Modal (or scroll to form)
            addCounterMeasure(dateStr, category);

            // Set flag: When this issue is saved, we update the day status to RED.
            pendingRedUpdate = {
                category: category,
                date: dateStr,
                status: 'red'
            };

            Swal.fire({
                position: 'top-end',
                icon: 'info',
                title: 'Pending Red Status',
                text: 'Status will be saved RED only after you Store the issue.',
                showConfirmButton: false,
                timer: 2500
            });
        });
    }
    // --- GRAY (Clear) ---
    else if (status === 'gray') {
        performStatusUpdate(category, dateStr, status);
    }
}

function performStatusUpdate(kpi, date, status) {
    return fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'update_day',
            kpi: kpi,
            date: date,
            status: status
        })
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Optional: Show partial success toast?
                // For now, Green/Blue/Orange reloads page on this chain end usually.
                // But we might want to delay reload if we are adding an issue (Orange).
                if (status !== 'orange') {
                    location.reload();
                }
            } else {
                Swal.fire('Error', data.message || 'Could not save status', 'error');
            }
        })
        .catch(err => Swal.fire('Error', 'Network error', 'error'));
}

// --- COUNTERMEASURES LOGIC ---
let cmData = typeof initialCM !== 'undefined' ? initialCM : [];

// Predefined Issues for each category (Dynamic Dropdown)
const predefinedIssues = {
    'S': [
        { value: 'ppe_damaged', label: '🦺 عطل في معدات الوقاية (PPE Damaged)' },
        { value: 'slip_trip', label: '⚠️ انزلاق/تعثر (Slip/Trip Hazard)' },
        { value: 'minor_injury', label: '🩹 إصابة عمل خفيفة (Minor Injury)' },
        { value: 'blocked_exit', label: '🚫 ممر مسدود (Blocked Exit)' },
        { value: 'electrical_hazard', label: '⚡ خطر كهربائي (Electrical Hazard)' },
        { value: 'other', label: '📝 أخرى (Other)' }
    ],
    'Q': [
        { value: 'sewing_defect', label: '🧵 عيب في الخياطة (Sewing Defect)' },
        { value: 'measurement_issue', label: '📏 مشكلة في القياسات (Measurement Issue)' },
        { value: 'stains_dirt', label: '🔴 بقع/أوساخ (Stains/Dirt)' },
        { value: 'fabric_mismatch', label: '🎨 خطأ في القماش/اللون (Fabric Mismatch)' },
        { value: 'accessories_defect', label: '🔘 عيب في الملحقات (Accessories Defect)' },
        { value: 'other', label: '📝 أخرى (Other)' }
    ],
    'D': [
        { value: 'material_delay', label: '📦 تأخر المواد الأولية (Material Delay)' },
        { value: 'machine_breakdown', label: '🔧 عطل في الآلة (Machine Breakdown)' },
        { value: 'absenteeism', label: '👷 غياب المشغلين (Absenteeism)' },
        { value: 'bottleneck', label: '⏳ عنق زجاجة (Process Bottleneck)' },
        { value: 'power_failure', label: '💡 انقطاع التيار (Power Failure)' },
        { value: 'other', label: '📝 أخرى (Other)' }
    ],
    '5S': [
        { value: 'items_not_in_place', label: '📍 أدوات غير مرتبة (Items Not in Place)' },
        { value: 'workstation_dirty', label: '🧹 نظافة المكان (Workstation Dirty)' },
        { value: 'fading_lines', label: '📐 ممرات غير مخططة (Fading Floor Lines)' },
        { value: 'excess_inventory', label: '📚 تكدس المخزون (Excess Inventory)' },
        { value: 'missing_labels', label: '🏷️ غياب الملصقات (Missing Labels)' },
        { value: 'other', label: '📝 أخرى (Other)' }
    ],
    'C': [
        { value: 'material_waste', label: '🗑️ هدر في المواد (Material Waste)' },
        { value: 'excess_energy', label: '💡 استهلاك طاقة زائد (Excess Energy)' },
        { value: 'unplanned_overtime', label: '⏰ عمل إضافي غير مبرر (Unplanned Overtime)' },
        { value: 'rework_cost', label: '🔄 إصلاحات متكررة (Rework Cost)' },
        { value: 'tool_breakage', label: '🔨 تلف أدوات (Tool Breakage)' },
        { value: 'other', label: '📝 أخرى (Other)' }
    ]
};

// Predefined Actions for each category (Cascading Dropdown)
const predefinedActions = {
    'S': [
        { label: '🦺 استبدال معدات الوقاية (Replace PPE)' },
        { label: '🧹 تنظيف انسكاب (Clean Spill)' },
        { label: '⚠️ وضع علامة تحذيرية (Place Warning Sign)' },
        { label: '🚫 إزالة العائق (Clear Obstruction)' },
        { label: '🩹 إسعاف أولي (First Aid Given)' },
        { label: '🔒 عزل المنطقة (Isolate Area)' },
        { label: '📝 أخرى (Other)' }
    ],
    'Q': [
        { label: '🔄 إعادة العمل (Rework)' },
        { label: '🗑️ إتلاف/خردة (Scrap)' },
        { label: '📦 عزل المنتج (Quarantine)' },
        { label: '⚙️ تعديل ضبط الآلة (Adjust Machine)' },
        { label: '📢 تنبيه الجودة (Quality Alert)' },
        { label: '🔍 فرز 100% (100% Sorting)' },
        { label: '📝 أخرى (Other)' }
    ],
    'D': [
        { label: '🔧 طلب صيانة عاجل (Call Maintenance)' },
        { label: '📦 استخدام مخزون الأمان (Use Buffer Stock)' },
        { label: '⏰ ساعات إضافية (Overtime)' },
        { label: '👥 إعادة توزيع العمال (Reassign Operators)' },
        { label: '🚚 تسريع المواد (Expedite Material)' },
        { label: '📝 أخرى (Other)' }
    ],
    '5S': [
        { label: '🧹 تنظيف فوري (Clean Immediately)' },
        { label: '📍 إعادة للمكان (Return to Home)' },
        { label: '🏷️ طباعة ملصق (Print Label)' },
        { label: '🔴 وضع بطاقة حمراء (Red Tagging)' },
        { label: '📐 تخطيط الحدود (Mark Boundaries)' },
        { label: '📝 أخرى (Other)' }
    ],
    'C': [
        { label: '🔌 إيقاف التشغيل (Shutdown)' },
        { label: '🔍 تحقيق في السبب (Investigate Root Cause)' },
        { label: '♻️ استرجاع/إعادة استخدام (Reuse/Recover)' },
        { label: '📊 مراجعة التخطيط (Review Planning)' },
        { label: '📝 أخرى (Other)' }
    ]
};

// Predefined Responsible Roles for each category
const predefinedResponsible = {
    'S': [
        { label: '👷 رئيس الفريق (Team Leader)' },
        { label: '🦺 مسؤول السلامة (HSE Officer)' },
        { label: '🔧 عامل الصيانة (Maintenance Tech)' },
        { label: '🧹 عامل النظافة (Cleaner)' },
        { label: '👔 مشرف الإنتاج (Supervisor)' }
    ],
    'Q': [
        { label: '🔍 مراقب الجودة (Quality Controller)' },
        { label: '👷 رئيس الفريق (Team Leader)' },
        { label: '⚙️ المكتب التقني (Technical/Methods)' },
        { label: '👔 مشرف الإنتاج (Supervisor)' }
    ],
    'D': [
        { label: '🔧 عامل الصيانة (Maintenance Tech)' },
        { label: '📦 مسؤول المخزن (Storekeeper)' },
        { label: '👷 رئيس الفريق (Team Leader)' },
        { label: '👔 مشرف الإنتاج (Supervisor)' },
        { label: '👥 إعادة توزيع (HR/Planning)' }
    ],
    '5S': [
        { label: '👷 رئيس الفريق (Team Leader)' },
        { label: '🧹 عامل النظافة (Cleaner)' },
        { label: '📦 مسؤول المخزن (Storekeeper)' },
        { label: '⚙️ المكتب التقني (Technical/Methods)' }
    ],
    'C': [
        { label: '👔 مشرف الإنتاج (Supervisor)' },
        { label: '👷 رئيس الفريق (Team Leader)' },
        { label: '🔧 عامل الصيانة (Maintenance Tech)' },
        { label: '📊 المحاسبة/الإدارة (Admin/Finance)' }
    ]
};

function getOptions(list, selectedValue, placeholder) {
    let options = `<option value="">${placeholder}</option>`;

    list.forEach(item => {
        const selected = selectedValue === item.label ? 'selected' : '';
        options += `<option value="${item.label}" ${selected}>${item.label}</option>`;
    });

    // Check if current value is custom (not in predefined list)
    if (selectedValue && !list.some(i => i.label === selectedValue)) {
        options += `<option value="${selectedValue}" selected>📝 ${selectedValue}</option>`;
    }

    return options;
}

function getIssueOptions(category, selectedValue) {
    return getOptions(predefinedIssues[category] || [], selectedValue, '-- اختر المشكلة --');
}

function getActionOptions(category, selectedValue) {
    return getOptions(predefinedActions[category] || [], selectedValue, '-- اختر الإجراء --');
}

function getResponsibleOptions(category, selectedValue) {
    return getOptions(predefinedResponsible[category] || [], selectedValue, '-- اختر المسؤول --');
}

function renderTable(data) {
    cmData = data;
    const tbody = document.querySelector('#cm-table tbody');
    if (!tbody) return;

    tbody.innerHTML = '';

    cmData.forEach((row, index) => {
        const tr = document.createElement('tr');
        const category = row.category || 'S';

        // Check if row is saved (has ID) -> Read Only Mode
        if (row.id) {
            let rowStyle = "background:#f8f9fa; color:#666;";
            let statusBadge = row.status;

            // Special styling for Soft Deleted items
            if (row.status === 'Deleted') {
                rowStyle = "background:#ffebee; color:#c62828; text-decoration: line-through;";
                statusBadge = "🗑️ Deleted (Recycle Bin)";
            }

            tr.innerHTML = `
                <td style="${rowStyle} font-weight:bold;">${category}</td>
                <td style="${rowStyle}">${row.issue}</td>
                <td style="${rowStyle}">${row.action_plan}</td>
                <td style="${rowStyle}">${row.responsible}</td>
                <td style="${rowStyle}">${row.due_date}</td>
                <td style="${rowStyle} font-weight:bold;">${statusBadge}</td>
                <td style="background:#f8f9fa; text-align:center;">${row.status === 'Deleted' ? '🚫' : '🔒'}</td>
            `;
        } else {
            // New Row -> Editable Mode
            // Category Select
            const catOptions = ['S', 'Q', 'D', '5S', 'C'].map(c =>
                `<option value="${c}" ${category === c ? 'selected' : ''}>${c}</option>`
            ).join('');

            // Dynamic Dropdowns based on category
            const issueOptions = getIssueOptions(category, row.issue);
            const actionOptions = getActionOptions(category, row.action_plan);
            const responsibleOptions = getResponsibleOptions(category, row.responsible);

            tr.innerHTML = `
                <td>
                    <select onchange="updateCategory(${index}, this.value)" style="font-weight:bold; min-width:60px;">
                        ${catOptions}
                    </select>
                </td>
                <td>
                    <select onchange="updateRow(${index}, 'issue', this.value)" style="min-width:180px; font-size:11px;">
                        ${issueOptions}
                    </select>
                </td>
                <td>
                    <select onchange="updateRow(${index}, 'action_plan', this.value)" style="min-width:160px; font-size:11px;">
                        ${actionOptions}
                    </select>
                </td>
                <td>
                    <select onchange="updateRow(${index}, 'responsible', this.value)" style="min-width:140px; font-size:11px;">
                        ${responsibleOptions}
                    </select>
                </td>
                <td><input type="date" value="${row.due_date || ''}" onchange="updateRow(${index}, 'due_date', this.value)"></td>
                <td>
                    <select onchange="updateRow(${index}, 'status', this.value)">
                        <option value="Open" ${row.status === 'Open' ? 'selected' : ''}>Open</option>
                        <option value="In Progress" ${row.status === 'In Progress' ? 'selected' : ''}>In Progress</option>
                        <option value="Done" ${row.status === 'Done' ? 'selected' : ''}>Done</option>
                    </select>
                </td>
                <td><button style="background:red; padding:5px; color:white; border:none; cursor:pointer;" onclick="deleteRow(${index})">X</button></td>
            `;
        }
        tbody.appendChild(tr);
    });
}

// Update category and refresh all cascading dropdowns
function updateCategory(index, newCategory) {
    cmData[index].category = newCategory;
    cmData[index].issue = ''; // Reset when category changes
    cmData[index].action_plan = ''; // Reset when category changes
    cmData[index].responsible = ''; // Reset when category changes
    renderTable(cmData);
    // Manual Save Required
}

function addCounterMeasure(targetDate = null, targetCategory = 'S') {
    // Default to Today's date if not provided
    const due = targetDate || new Date().toISOString().split('T')[0];
    const cat = targetCategory;

    cmData.push({ category: cat, issue: '', action_plan: '', responsible: '', due_date: due, status: 'Open' });
    renderTable(cmData);

    // Scroll to the newly added row (bottom of table)
    setTimeout(() => {
        const table = document.getElementById('cm-table');
        if (table) {
            const rows = table.querySelectorAll('tbody tr');
            const lastRow = rows[rows.length - 1];
            if (lastRow) {
                lastRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                // Optional: Highlight the row
                lastRow.style.backgroundColor = '#fff3cd';
                setTimeout(() => lastRow.style.backgroundColor = '', 2000);
            } else {
                table.scrollIntoView({ behavior: 'smooth' });
            }
        }
    }, 100); // Small delay to allow DOM render
    // Manual Save Required
}

function updateRow(index, field, value) {
    cmData[index][field] = value;
    // Manual Save Required
}

function deleteRow(index) {
    cmData.splice(index, 1);
    renderTable(cmData);
    // Manual Save Required
}

function confirmSaveCM() {
    Swal.fire({
        title: '⚠️ Store Data? / تخزين ؟',
        html: `Are you sure? Once saved, this data <b>CANNOT be deleted or modified</b>.<br><br>
               هل أنت متأكد؟ بمجرد التخزين، <b>لا يمكن حذف أو تعديل</b> هذه البيانات.<br><br>
               <span style='color:red; font-weight:bold;'>Action is Irreversible!</span>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Yes, Store it! / نعم، تخزين',
        cancelButtonText: 'Cancel / إلغاء'
    }).then((result) => {
        if (result.isConfirmed) {
            saveCM();
        }
    });
}

function saveCM() {
    fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'save_countermeasures',
            data: cmData
        })
    })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Check if we have a pending RED status update
                if (pendingRedUpdate) {
                    performStatusUpdate(pendingRedUpdate.category, pendingRedUpdate.date, 'red')
                        .then(() => {
                            pendingRedUpdate = null;
                            Swal.fire('Saved!', 'Issue and Red Status stored.', 'success').then(() => location.reload());
                        });
                } else {
                    Swal.fire('Saved!', 'Data has been stored.', 'success').then(() => location.reload());
                }
            } else {
                Swal.fire('Error', 'Failed to save.', 'error');
            }
        });
}

// --- WELCOME MESSAGES / GAMIFICATION ---
function checkWelcomeMessage() {
    // REMOVED sessionStorage check to make it persistent on every load

    // Clear existing banner if any
    const bannerId = 'status-banner';
    const existingBanner = document.getElementById(bannerId);
    if (existingBanner) existingBanner.remove();

    if (missingAssignments.length > 0) {
        // 🚨 SCENARIO 1: RED ALERT (Missing Data)

        // 1. Inject Red Banner
        const banner = document.createElement('div');
        banner.id = bannerId;
        banner.style.cssText = "background: #dc3545; color: white; padding: 15px; text-align: center; font-weight: bold; font-size: 1.1em; position: sticky; top: 0; z-index: 9999; box-shadow: 0 2px 5px rgba(0,0,0,0.2);";
        banner.innerHTML = "⚠️ تحذير إداري: لديك بيانات ناقصة! يرجى التسوية فوراً. <br> <span style='font-size:0.8em; font-weight:normal;'>Admin Warning: You have missing data! Please fix immediately.</span>";
        document.body.prepend(banner);

        // 2. Show Modal
        let missingList = missingAssignments.map(m => `<li>🔴 <b>${m.category}</b>: ${m.date}</li>`).join('');

        Swal.fire({
            title: '⚠️ تنبيه إداري: بيانات ناقصة!<br>Admin Alert: Missing Data!',
            html: `
                <div style="text-align:left; direction:ltr;">
                    <p>Hello <b>${userName}</b>,</p>
                    <p>We noticed missing updates for the past days. <b>Data accuracy is your responsibility.</b></p>
                    <p>🛑 <b>Please fix these pending items immediately:</b></p>
                    <ul style="list-style:none; padding:10px; background:#fff3cd; border:1px solid #ffeeba;">${missingList}</ul>
                    <p>You cannot proceed comfortably before closing these gaps.</p>
                </div>
                <div style="text-align:right; direction:rtl; margin-top:15px; border-top:1px solid #eee; padding-top:10px;">
                    <p>مرحباً <b>${userName}</b>،</p>
                    <p>لاحظنا وجود بيانات ناقصة للأيام الماضية. <b>دقة البيانات مسؤوليتك.</b></p>
                    <p>🛑 <b>يرجى تسوية الوضعية فوراً لهذه التواريخ:</b></p>
                </div>
            `,
            icon: 'warning',
            confirmButtonText: '👈 الذهاب لتسوية المتأخرات (Fix Now)',
            confirmButtonColor: '#d33',
            allowOutsideClick: false
        });

    } else {
        // 🌟 SCENARIO 2: GREEN/GOLD (Discipline & Engagement)

        // 1. Inject Green Banner
        const banner = document.createElement('div');
        banner.id = bannerId;
        banner.style.cssText = "background: #28a745; color: white; padding: 10px; text-align: center; font-weight: bold; font-size: 1em; position: sticky; top: 0; z-index: 9999; box-shadow: 0 2px 5px rgba(0,0,0,0.1);";
        banner.innerHTML = "🌟 شكراً لالتزامك! سجلاتك محدثة تماماً. <br> <span style='font-size:0.8em; font-weight:normal;'>Thank you for your commitment! Your records are up to date.</span>";
        document.body.prepend(banner);

        // 2. Show Modal
        Swal.fire({
            title: '🌟 شكراً لالتزامك واحترافيتك!<br>Thank you for your commitment!',
            html: `
                <div style="text-align:center;">
                    <p style="font-size:1.1em;"> أهلاً بك مجدداً <b>${userName}</b> 👋</p>
                    <p>نود أن نشكرك على انضباطك ومواظبتك؛ سجلاتك <b>محدثة تماماً</b> ولا يوجد لديك أي دين سابق. 👏</p>
                    <p><b>هذا هو مستوى القيادة الذي نفتخر به في Candytex!</b> 🏅</p>
                    <hr>
                    <p style="color:#28a745; font-weight:bold;">🎯 مهمتك اليوم:</p>
                    <p>لنمنح هذا اليوم التقييم الذي يستحقه بكل شفافية ومصداقية.</p>
                </div>
            `,
            icon: 'success',
            confirmButtonText: '✨ ابدأ تقييم اليوم (Start Today)',
            confirmButtonColor: '#28a745',
            backdrop: `
                rgba(0,0,123,0.4)
                url("https://media.giphy.com/media/l0MYt5jPR6tTSTPYQ/giphy.gif")
                left top
                no-repeat
            `
        });
    }
}

// Run Welcome Check
setTimeout(checkWelcomeMessage, 500);

// --- MOBILE SIDEBAR TOGGLE ---
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
    document.body.style.overflow = sidebar.classList.contains('open') ? 'hidden' : '';
}

function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
    document.body.style.overflow = '';
}

// Close sidebar on window resize (if desktop)
window.addEventListener('resize', function () {
    if (window.innerWidth > 768) {
        closeSidebar();
    }
});
