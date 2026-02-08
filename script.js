// Initial Render of Countermeasures
document.addEventListener('DOMContentLoaded', () => {
    if (typeof initialCM !== 'undefined') {
        renderTable(initialCM);
    }
    // Create status modal dynamically
    createStatusModal();
    // Create issue modal dynamically
    createIssueModal();
});

// --- GLOBAL VARIABLES ---
let currentDay = null;
let currentCategory = null;
let targetYear = null;
let targetMonth = null;
let pendingRedUpdate = null;
let cmData = typeof initialCM !== 'undefined' ? initialCM : [];

// --- CONSTANTS & DICTIONARIES ---
const catNames = { 'S': 'Safety / السلامة', 'Q': 'Quality / الجودة', 'D': 'Delivery / التسليم', '5S': '5S / التحسين', 'C': 'Cost / التكلفة' };

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

const predefinedResponsible = {
    'S': ['👷 رئيس الفريق (Team Leader)', '🦺 مسؤول السلامة (HSE Officer)', '🔧 عامل الصيانة (Maintenance Tech)', '🧹 عامل النظافة (Cleaner)', '👔 مشرف الإنتاج (Supervisor)'],
    'Q': ['🔍 مراقب الجودة (Quality Controller)', '👷 رئيس الفريق (Team Leader)', '⚙️ المكتب التقني (Technical/Methods)', '👔 مشرف الإنتاج (Supervisor)'],
    'D': ['🔧 عامل الصيانة (Maintenance Tech)', '📦 مسؤول المخزن (Storekeeper)', '👷 رئيس الفريق (Team Leader)', '👔 مشرف الإنتاج (Supervisor)', '👥 إعادة توزيع (HR/Planning)'],
    '5S': ['👷 رئيس الفريق (Team Leader)', '🧹 عامل النظافة (Cleaner)', '📦 مسؤول المخزن (Storekeeper)', '⚙️ المكتب التقني (Technical/Methods)'],
    'C': ['👔 مشرف الإنتاج (Supervisor)', '👷 رئيس الفريق (Team Leader)', '🔧 عامل الصيانة (Maintenance Tech)', '📊 المحاسبة/الإدارة (Admin/Finance)']
};

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


// --- ISSUE MODAL (UI) ---
function createIssueModal() {
    if (document.getElementById('issueModal')) return;

    const modal = document.createElement('div');
    modal.id = 'issueModal';
    // Mobile-friendly styling: Full screen on small devices, centered card on large
    modal.style.cssText = 'display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); justify-content:center; align-items:center; z-index:1100; padding:10px; overflow-y:auto;';

    modal.innerHTML = `
        <div style="background:white; border-radius:12px; width:100%; max-width:600px; box-shadow:0 4px 15px rgba(0,0,0,0.3); overflow:hidden;">
            <div style="background:#007bff; padding:15px; color:white; display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0;">🛠️ Add New Issue / إضافة مشكلة</h3>
                <button onclick="closeIssueModal()" style="background:none; border:none; color:white; font-size:24px; cursor:pointer;">&times;</button>
            </div>
            
            <div style="padding:20px; max-height:80vh; overflow-y:auto;">
                
                <!-- Category Select -->
                <div style="margin-bottom:15px;">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;">Category / الفئة</label>
                    <select id="modal_cat" onchange="updateModalOptions()" style="width:100%; padding:12px; border:1px solid #ccc; border-radius:6px; font-size:16px;">
                        <option value="S">Safety / السلامة</option>
                        <option value="Q">Quality / الجودة</option>
                        <option value="D">Delivery / التسليم</option>
                        <option value="5S">5S / التحسين</option>
                        <option value="C">Cost / التكلفة</option>
                    </select>
                </div>

                <!-- Issue Select -->
                <div style="margin-bottom:15px;">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;">Issue / المشكلة</label>
                    <select id="modal_issue" style="width:100%; padding:12px; border:1px solid #ccc; border-radius:6px; font-size:16px;">
                        <!-- Populated dynamic -->
                    </select>
                </div>

                <!-- Action Select -->
                <div style="margin-bottom:15px;">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;">Action / الإجراء</label>
                    <select id="modal_action" style="width:100%; padding:12px; border:1px solid #ccc; border-radius:6px; font-size:16px;">
                         <!-- Populated dynamic -->
                    </select>
                </div>

                <!-- Responsible Select -->
                <div style="margin-bottom:15px;">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;">Who / المسؤول</label>
                    <select id="modal_who" style="width:100%; padding:12px; border:1px solid #ccc; border-radius:6px; font-size:16px;">
                         <!-- Populated dynamic -->
                    </select>
                </div>

                <!-- Due Date & Status (Hidden for new) -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:15px;">
                    <div>
                        <label style="display:block; font-weight:bold; margin-bottom:5px;">Due Date / الموعد</label>
                        <input type="date" id="modal_date" style="width:100%; padding:12px; border:1px solid #ccc; border-radius:6px; font-size:16px;">
                    </div>
                     <div>
                        <label style="display:block; font-weight:bold; margin-bottom:5px;">Status / الحالة</label>
                         <input type="text" value="Open" readonly style="width:100%; padding:12px; background:#f0f0f0; border:1px solid #ccc; border-radius:6px; font-size:16px; color:#666;">
                    </div>
                </div>

                <div style="margin-top:20px; display:flex; gap:10px;">
                    <button onclick="submitIssueFromModal()" style="flex:1; background:#28a745; color:white; padding:15px; border:none; border-radius:8px; font-size:18px; font-weight:bold; cursor:pointer;">💾 ADD / إضافة</button>
                    <button onclick="closeIssueModal()" style="flex:0; background:#6c757d; color:white; padding:15px 25px; border:none; border-radius:8px; font-size:16px; cursor:pointer;">Cancel</button>
                </div>

            </div>
        </div>
    `;

    document.body.appendChild(modal);
}

// Open the Modal
function openIssueModal(presetCategory = null, presetDate = null) {
    const modal = document.getElementById('issueModal');
    if (!modal) return;

    // Reset Fields
    document.getElementById('modal_cat').value = presetCategory || 'S';

    // Date Logic
    const today = new Date().toISOString().split('T')[0];
    const dateInput = document.getElementById('modal_date');
    dateInput.value = presetDate || today;
    dateInput.max = today; // Prevent future dates

    // Trigger update for dropdowns
    updateModalOptions();

    modal.style.display = 'flex';
}

function closeIssueModal() {
    document.getElementById('issueModal').style.display = 'none';
}

function updateModalOptions() {
    const cat = document.getElementById('modal_cat').value;

    // Helper to generic options
    const populate = (id, list, placeholder) => {
        let opts = `<option value="">${placeholder}</option>`;
        list.forEach(i => opts += `<option value="${i.label || i.value}">${i.label || i.value}</option>`); // Handle mixed objects
        // Specialized handling for issues (value vs label)
        if (id === 'modal_issue') {
            opts = `<option value="">${placeholder}</option>`;
            list.forEach(i => opts += `<option value="${i.label}">${i.label}</option>`);
        }
        // Specialized handling for simple arrays
        if (typeof list[0] === 'string') {
            opts = `<option value="">${placeholder}</option>`;
            list.forEach(i => opts += `<option value="${i}">${i}</option>`);
        }
        document.getElementById(id).innerHTML = opts;
    };

    populate('modal_issue', predefinedIssues[cat] || [], '-- Select / اختر --');
    populate('modal_action', predefinedActions[cat] || [], '-- Select / اختر --');

    const respList = predefinedResponsible[cat] || [];
    let respOpts = `<option value="">-- Select / اختر --</option>`;
    respList.forEach(r => respOpts += `<option value="${r}">${r}</option>`);
    document.getElementById('modal_who').innerHTML = respOpts;
}

function submitIssueFromModal() {
    const cat = document.getElementById('modal_cat').value;
    const issue = document.getElementById('modal_issue').value;
    const action = document.getElementById('modal_action').value;
    const who = document.getElementById('modal_who').value;
    const date = document.getElementById('modal_date').value;

    if (!cat || !issue || !action || !who || !date) {
        // Highlight empty fields
        const fields = [
            { id: 'modal_issue', val: issue, name: 'المشكلة / Issue' },
            { id: 'modal_action', val: action, name: 'الإجراء / Action' },
            { id: 'modal_who', val: who, name: 'المسؤول / Who' },
            { id: 'modal_date', val: date, name: 'الموعد / Due Date' }
        ];
        const missing = [];
        fields.forEach(f => {
            const el = document.getElementById(f.id);
            if (!f.val) {
                el.style.border = '2px solid #dc3545';
                el.style.boxShadow = '0 0 5px rgba(220,53,69,0.4)';
                missing.push(f.name);
            } else {
                el.style.border = '1px solid #ccc';
                el.style.boxShadow = 'none';
            }
        });
        Swal.fire({ title: '⚠️ حقول ناقصة', html: missing.join('<br>'), icon: 'warning', customClass: { container: 'swal-over-modal' } });
        return;
    }

    // Add to Local Data
    const newRow = {
        category: cat,
        issue: issue,
        action_plan: action,
        responsible: who,
        due_date: date,
        status: 'Open' // Default
    };

    cmData.push(newRow);
    renderTable(cmData);
    closeIssueModal();

    // Scroll to bottom
    setTimeout(() => {
        const table = document.getElementById('cm-table');
        const rows = table.querySelectorAll('tbody tr');
        const lastRow = rows[rows.length - 1];
        if (lastRow) {
            lastRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
            lastRow.style.backgroundColor = '#d4edda';
            setTimeout(() => lastRow.style.backgroundColor = '', 2000);
        }
    }, 100);
}


// --- STATUS MODAL (Existing Logic, slightly cleaned) ---
function createStatusModal() {
    if (document.getElementById('statusModal')) return;
    const modal = document.createElement('div');
    modal.id = 'statusModal';
    modal.style.cssText = 'display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); justify-content:center; align-items:center; z-index:1000;';
    modal.innerHTML = `
        <div style="background:white; padding:30px; border-radius:15px; text-align:center; max-width:400px; width:90%;">
            <h3 id="modalTitle" style="margin-bottom:20px;">تحديث الحالة</h3>
            <div style="display:grid; grid-template-columns:repeat(2,1fr); gap:10px; margin-bottom:20px;">
                <button onclick="selectStatus('green')" style="background:#28a745; color:white; padding:15px; border:none; border-radius:8px; cursor:pointer; font-size:16px;">✓ موافق<br><small>تم تحقيق الهدف</small></button>
                <button onclick="selectStatus('orange')" style="background:#fd7e14; color:white; padding:15px; border:none; border-radius:8px; cursor:pointer; font-size:16px;">⚠️ إجراء<br><small>يحتاج انتباه</small></button>
                <button onclick="selectStatus('red')" style="background:#dc3545; color:white; padding:15px; border:none; border-radius:8px; cursor:pointer; font-size:16px;">✗ خطر<br><small>لم يتحقق الهدف</small></button>
                <button onclick="selectStatus('blue')" style="background:#007bff; color:white; padding:15px; border:none; border-radius:8px; cursor:pointer; font-size:16px;">📅 عطلة<br><small>يوم عطلة</small></button>
            </div>
            <button onclick="selectStatus('gray')" style="background:#6c757d; color:white; padding:10px 30px; border:none; border-radius:8px; cursor:pointer; margin-bottom:15px;">○ مسح<br><small>إعادة تعيين</small></button>
            <br>
            <button onclick="closeModal()" style="background:#eee; color:#333; padding:10px 30px; border:none; border-radius:8px; cursor:pointer;">إلغاء</button>
        </div>
    `;
    document.body.appendChild(modal);
}

function openDate(category, dateKey, currentStatus) {
    const parts = dateKey.split('-');
    openModal(category, parseInt(parts[2]), parseInt(parts[0]), parseInt(parts[1]));
}

function openModal(type, day, currentYear, currentMonth) {
    currentDay = day;
    currentCategory = type;
    targetYear = currentYear;
    targetMonth = currentMonth;

    const now = new Date();
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const selectedDate = new Date(currentYear, currentMonth - 1, day);

    if (selectedDate > today) {
        Swal.fire({ icon: 'warning', title: 'Future Date', text: 'Cannot fill future dates.' });
        return;
    }
    if (selectedDate.getTime() === today.getTime()) {
        if (now.getUTCHours() < 19) {
            Swal.fire({ icon: 'warning', title: 'Start 19:00', text: 'Reporting starts at 19:00 GMT.' });
            return;
        }
    }
    const diffDays = Math.ceil(Math.abs(today - selectedDate) / (1000 * 60 * 60 * 24));
    if (diffDays > 7) {
        Swal.fire({ icon: 'error', title: 'Locked', text: 'Editing locked for dates older than 7 days.' });
        return;
    }

    const dateStr = `${currentYear}-${currentMonth}-${day}`;
    document.getElementById('modalTitle').innerHTML = `Update <strong>${catNames[type]}</strong><br><small>${dateStr}</small>`;
    document.getElementById('statusModal').style.display = 'flex';
}

function closeModal() { document.getElementById('statusModal').style.display = 'none'; }

function selectStatus(status) {
    const dateStr = `${targetYear}-${targetMonth}-${currentDay}`;
    const category = currentCategory;
    const msg = statusMessages[status] && statusMessages[status][category] ? statusMessages[status][category] : "Confirm?";
    closeModal();

    if (status === 'green' || status === 'blue') {
        Swal.fire({ title: 'تأكيد', html: msg, icon: 'question', showCancelButton: true, confirmButtonText: '✅ تأكيد', cancelButtonText: 'إلغاء' }).then((r) => {
            if (r.isConfirmed) performStatusUpdate(category, dateStr, status);
        });
    } else if (status === 'orange') {
        Swal.fire({ title: 'تحذير', html: msg, icon: 'warning', showCancelButton: true, confirmButtonText: '⚠️ إضافة مشكلة', cancelButtonText: 'حفظ فقط' }).then((r) => {
            performStatusUpdate(category, dateStr, status, r.isConfirmed);
            if (r.isConfirmed) openIssueModal(category, dateStr);
        });
    } else if (status === 'red') {
        Swal.fire({ title: 'توقف', html: msg, icon: 'error', confirmButtonColor: '#d33', confirmButtonText: '🚨 تسجيل المشكلة' }).then(() => {
            openIssueModal(category, dateStr);
            pendingRedUpdate = { category, date: dateStr, status: 'red' };
        });
    } else if (status === 'gray') {
        performStatusUpdate(category, dateStr, status);
    }
}

function performStatusUpdate(kpi, date, status, skipReload = false) {
    return fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_day', kpi, date, status, csrf_token: (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '') })
    }).then(r => r.json()).then(d => {
        if (d.success && !skipReload) location.reload();
        else if (!d.success) Swal.fire('Error', 'Update failed', 'error');
    });
}

// --- TABLE RENDER LOGIC (READ ONLY + ACTIONS) ---
function renderTable(data) {
    cmData = data;
    const tbody = document.querySelector('#cm-table tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    cmData.forEach((row, index) => {
        const tr = document.createElement('tr');

        const isSaved = !!row.id; // Assuming ID comes from DB
        const isDeleted = row.status === 'Deleted';
        const catLabel = catNames[row.category] || row.category;

        tr.innerHTML = `
            <td style="font-weight:bold;">${catLabel}</td>
            <td style="font-size:0.9em;">${row.issue}</td>
            <td style="font-size:0.9em;">${row.action_plan}</td>
            <td style="font-size:0.85em;">${row.responsible}</td>
            <td>${row.due_date}</td>
            <td><span class="badge ${row.status}">${row.status}</span></td>
            <td style="white-space:nowrap;">
                ${isDeleted ? '🚫' : `
                    <button class="btn-action btn-save" onclick="saveRow(${index})">💾</button>
                    <button class="btn-action btn-del" onclick="deleteRow(${index})">🗑️</button>
                `}
            </td>
        `;

        if (isDeleted) tr.style.cssText = "background:#ffebee; text-decoration:line-through; opacity:0.7;";
        else if (!isSaved) tr.style.cssText = "background:#fffde7; border-left: 3px solid #ffc107;"; // Highlight unsaved

        tbody.appendChild(tr);
    });
}

function addCounterMeasure(date = null, cat = 'S') {
    openIssueModal(cat, date);
}

// --- SAVE ROW ACTION ---
function saveRow(index) {
    const row = cmData[index];
    if (row.id) return;

    Swal.fire({
        title: 'Confirm Save?',
        text: 'Save this issue permanently?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Save 💾'
    }).then((r) => {
        if (r.isConfirmed) {
            // Call API
            fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_countermeasures',
                    data: [row],
                    csrf_token: (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '')
                })
            })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Saved', 'Issue saved successfully', 'success').then(() => {
                            if (pendingRedUpdate) {
                                performStatusUpdate(pendingRedUpdate.category, pendingRedUpdate.date, 'red');
                            } else {
                                location.reload();
                            }
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Save failed', 'error');
                    }
                });
        }
    });
}

// --- DELETE ROW ACTION ---
function deleteRow(index) {
    Swal.fire({
        title: 'Delete Issue?',
        text: 'Are you sure you want to remove this item?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, Delete 🗑️'
    }).then((r) => {
        if (r.isConfirmed) {
            cmData.splice(index, 1);
            renderTable(cmData);
        }
    });
}

// --- CSS INJECTION FOR STYLES ---
const style = document.createElement('style');
style.textContent = `
    .badge { padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 0.8em; }
    .badge.Open { background: #e0e0e0; color: #333; }
    .badge.Done { background: #d4edda; color: #155724; }
    .btn-action { border: none; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 1.1em; transition: transform 0.1s; }
    .btn-action:hover { transform: scale(1.1); }
    .btn-save { background: #28a745; color: white; margin-right: 5px; }
    .btn-del { background: #dc3545; color: white; }
    .swal-over-modal { z-index: 9999 !important; }
`;
document.head.appendChild(style);


// --- MOBILE SIDEBAR ---
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.querySelector('.sidebar-overlay').classList.toggle('active');
}
function closeSidebar() {
    toggleSidebar();
}
