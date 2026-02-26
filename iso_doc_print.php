<?php
session_start();
require 'db.php';
require 'includes/auth.php';

if (!isset($_SESSION['user_cin'])) {
    header("Location: index.php");
    exit;
}

$doc_id = intval($_GET['id'] ?? 0);
if (!$doc_id) {
    echo "⚠️ No document specified.";
    exit;
}

$doc = $pdo->prepare("SELECT * FROM iso_documents WHERE id = ?");
$doc->execute([$doc_id]);
$doc = $doc->fetch();
if (!$doc) {
    echo "⚠️ Document not found.";
    exit;
}

$revisions = $pdo->prepare("SELECT * FROM doc_revisions WHERE doc_id = ? ORDER BY created_at ASC");
$revisions->execute([$doc_id]);
$revisions = $revisions->fetchAll();

// ═══════════════════════════════════════════════════
// TEMPLATE CONTENT PER DOCUMENT
// ═══════════════════════════════════════════════════
$templates = [
    'Quality Manual' => [
        'purpose' => 'This manual defines the Quality Management System (QMS) of CANDYTEX S.A.R.L in compliance with ISO 9001:2015. It establishes the framework for quality planning, control, assurance, and continuous improvement across all operations.',
        'purpose_ar' => 'يحدد هذا الدليل نظام إدارة الجودة لشركة كانديتكس وفقاً لمعيار ISO 9001:2015. يُرسي الإطار العام لتخطيط الجودة ومراقبتها وضمانها والتحسين المستمر.',
        'scope' => 'All departments and processes within CANDYTEX S.A.R.L, including Cutting, Sewing, Finishing, Quality, Warehouse, Maintenance, Purchasing, and Administration.',
        'references' => ['ISO 9001:2015 — Quality Management Systems', 'ISO 19011:2018 — Guidelines for Auditing', 'Company Quality Policy', 'Applicable legal and regulatory requirements'],
        'sections' => [
            ['Context of the Organization / سياق المنظمة', 'CANDYTEX operates in the textile manufacturing sector, producing garments for international markets. The organization has identified internal and external issues, interested parties, and their requirements as inputs to the QMS scope.'],
            ['Leadership & Quality Policy / القيادة وسياسة الجودة', 'Top management demonstrates leadership and commitment by establishing and communicating the Quality Policy, ensuring integration of QMS requirements into business processes, and promoting risk-based thinking.'],
            ['Planning / التخطيط', 'The organization addresses risks and opportunities (see Risk Register), establishes quality objectives at relevant functions and levels, and plans changes to the QMS in a systematic manner.'],
            ['Support / الدعم', 'Resources (people, infrastructure, environment, monitoring equipment, organizational knowledge) are determined and provided. Competence, awareness, communication, and documented information are managed.'],
            ['Operation / التشغيل', 'Operational planning and control, requirements for products, design and development, control of externally provided processes, production and service provision, release and control of nonconforming outputs.'],
            ['Performance Evaluation / تقييم الأداء', 'Monitoring, measurement, analysis and evaluation; internal audit program; management review — all conducted at planned intervals.'],
            ['Improvement / التحسين', 'Nonconformity and corrective action, continual improvement through analysis of data, audit findings, management review outputs, and customer feedback.'],
        ],
    ],
    'Quality Policy' => [
        'purpose' => 'To communicate the Quality Policy of CANDYTEX S.A.R.L, demonstrating top management commitment to quality, customer satisfaction, and continual improvement.',
        'purpose_ar' => 'التعبير عن سياسة الجودة لشركة كانديتكس، مع إظهار التزام الإدارة العليا بالجودة ورضا العملاء والتحسين المستمر.',
        'scope' => 'All employees, suppliers, and stakeholders of CANDYTEX S.A.R.L.',
        'references' => ['ISO 9001:2015 §5.2 — Quality Policy'],
        'sections' => [
            ['Quality Policy Statement / بيان سياسة الجودة', "CANDYTEX S.A.R.L is committed to:\n• Delivering products that consistently meet customer requirements and applicable regulations\n• Maintaining and continually improving the effectiveness of our Quality Management System\n• Enhancing customer satisfaction through prompt delivery, quality products, and responsive service\n• Providing a safe and productive working environment for all employees\n• Ensuring competence through ongoing training and development\n• Setting and reviewing measurable quality objectives at all levels"],
            ['Management Commitment / التزام الإدارة', 'The Factory Director ensures this policy is understood, implemented, and maintained at all levels of the organization. It is reviewed annually during Management Review meetings for continuing suitability.'],
            ['Communication / التواصل', 'This policy is displayed in all work areas, communicated to all employees during induction and training, and made available to interested parties upon request.'],
        ],
    ],
    'HSE Policy' => [
        'purpose' => 'To define the Health, Safety and Environment (HSE) policy of CANDYTEX, ensuring a safe workplace and environmental compliance.',
        'purpose_ar' => 'تحديد سياسة الصحة والسلامة والبيئة لشركة كانديتكس لضمان بيئة عمل آمنة والامتثال البيئي.',
        'scope' => 'All employees, visitors, contractors, and operations within CANDYTEX premises.',
        'references' => ['Moroccan Labour Code (Code du Travail)', 'ISO 45001:2018 — OHS Management', 'ISO 14001:2015 — Environmental Management', 'Fire Safety Regulations'],
        'sections' => [
            ['Safety Commitment / الالتزام بالسلامة', "CANDYTEX is committed to:\n• Preventing workplace injuries and ill health\n• Providing safe equipment, proper PPE, and adequate training\n• Maintaining clean and organized workspaces (5S methodology)\n• Emergency preparedness with regular evacuation drills\n• Compliance with all applicable HSE legislation"],
            ['Environmental Responsibility / المسؤولية البيئية', "• Minimize waste generation and promote recycling of fabric scraps\n• Proper management of chemicals and hazardous materials\n• Energy conservation and efficient resource utilization\n• Noise control within regulatory limits"],
            ['Roles & Responsibilities / الأدوار والمسؤوليات', "• HSE Officer: Daily inspections, incident investigation, training coordination\n• Supervisors: Enforce safety rules, report hazards, ensure PPE usage\n• Employees: Follow safety procedures, report unsafe conditions\n• Safety Committee: Monthly meetings, risk assessments, improvement recommendations"],
        ],
    ],
    'Incoming Material Inspection' => [
        'purpose' => 'To establish the procedure for inspecting and verifying incoming raw materials (fabrics, threads, accessories) to ensure conformance to purchase specifications.',
        'purpose_ar' => 'وضع إجراء لفحص المواد الأولية الواردة (الأقمشة، الخيوط، الإكسسوارات) للتحقق من مطابقتها لمواصفات الشراء.',
        'scope' => 'All incoming materials received at CANDYTEX warehouse.',
        'references' => ['ISO 9001:2015 §8.4 — Control of externally provided processes', 'Supplier Quality Agreements', 'AQL Sampling Tables (ISO 2859-1)'],
        'sections' => [
            ['Receipt & Documentation / الاستلام والتوثيق', "1. Verify delivery documents match Purchase Order (PO)\n2. Check quantity, reference numbers, and lot/batch markings\n3. Record arrival in Incoming Material Log\n4. Segregate materials in 'Pending Inspection' area"],
            ['Fabric Inspection / فحص القماش', "1. Visual inspection for defects: stains, holes, weaving faults, color variation\n2. Check fabric weight (g/m²) against specification\n3. Verify width and length per roll\n4. Color matching against approved lab dip/strike-off\n5. Apply 4-Point Inspection System for defect scoring\n6. Accept/Reject based on AQL criteria"],
            ['Accessories Inspection / فحص الإكسسوارات', "1. Visual check for damage, color match, and dimensions\n2. Verify quantities per packaging\n3. Functional testing (zippers, buttons, snaps) — sample basis\n4. Label verification: content, care symbols, sizes"],
            ['Disposition / القرار', "• ACCEPTED: Move to authorized storage, update inventory\n• ON HOLD: Notify Quality Manager for further evaluation\n• REJECTED: Isolate, mark with Red Tag, initiate Supplier NCR\n• Record all decisions in Inspection Report Form"],
        ],
    ],
    'In-Process Quality Control' => [
        'purpose' => 'To define the in-line and end-of-line quality control procedures during production to ensure product conformity.',
        'purpose_ar' => 'تحديد إجراءات مراقبة الجودة أثناء الإنتاج وفي نهاية الخط لضمان مطابقة المنتج.',
        'scope' => 'All sewing lines and production operations.',
        'references' => ['ISO 9001:2015 §8.5.1 — Control of production', 'Technical Specifications per Order', 'AQL Standards'],
        'sections' => [
            ['In-Line Inspection / الفحص أثناء الإنتاج', "1. Roving Quality Controller checks each operator every 2 hours\n2. Verify: seam alignment, stitch density (SPI), measurement tolerances\n3. Check thread tension, needle condition, machine settings\n4. Record defects on In-Line Inspection Sheet\n5. If defect rate > 5%: STOP line, alert Supervisor, implement corrective action"],
            ['End-of-Line Inspection / فحص نهاية الخط', "1. 100% visual inspection of completed garments\n2. Check all operations per Tech Pack sequence\n3. Measure critical points: chest, length, sleeve, collar\n4. Verify labels, hang tags, and packaging requirements\n5. Classify defects: Critical / Major / Minor"],
            ['AQL Audit / تدقيق AQL', "1. Apply AQL 2.5 for Major defects, AQL 4.0 for Minor defects\n2. Random sampling per lot size (ISO 2859-1 tables)\n3. Pass: Proceed to packaging | Fail: 100% re-inspection\n4. Record results in Final Audit Report"],
        ],
    ],
    'Final Product Inspection' => [
        'purpose' => 'To establish the final inspection and acceptance criteria before shipment to ensure all customer requirements are met.',
        'purpose_ar' => 'وضع معايير الفحص النهائي والقبول قبل الشحن لضمان استيفاء جميع متطلبات العميل.',
        'scope' => 'All finished goods ready for packing and shipment.',
        'references' => ['ISO 9001:2015 §8.6 — Release of products', 'Customer Quality Specifications', 'AQL Tables'],
        'sections' => [
            ['Pre-Shipment Audit / تدقيق ما قبل الشحن', "1. Conducted after 80% of order is packed\n2. Random sampling per AQL 2.5 (Major) / AQL 4.0 (Minor)\n3. Full measurement audit on size set\n4. Packaging, labeling, and carton marking verification\n5. Metal detection test (needle-free guarantee)"],
            ['Acceptance Criteria / معايير القبول', "• Zero tolerance: Wrong size/color, visible stains, holes, missing labels\n• Major defects: Open seams, skipped stitches, puckering, shade variation\n• Minor defects: Loose threads, slight press marks, minor alignment\n• AQL Pass = SHIP | AQL Fail = 100% Sort + Re-audit"],
            ['Documentation / التوثيق', "1. Complete Final Inspection Report\n2. Attach measurement chart and photo evidence\n3. Issue Certificate of Conformance (if required)\n4. Archive records for minimum 3 years"],
        ],
    ],
    'Non-Conformance Control' => [
        'purpose' => 'To define the process for identifying, documenting, segregating, and dispositioning non-conforming products and materials.',
        'purpose_ar' => 'تحديد عملية تحديد وتوثيق وعزل ومعالجة المنتجات والمواد غير المطابقة.',
        'scope' => 'All non-conforming products, materials, and processes detected at any stage.',
        'references' => ['ISO 9001:2015 §8.7 — Control of nonconforming outputs', 'ISO 9001:2015 §10.2 — Nonconformity and corrective action', 'CANDYTEX NCR/CAR Module'],
        'sections' => [
            ['Identification / التحديد', "1. Any employee who detects a nonconformity must immediately report it\n2. Supervisor isolates the nonconforming item\n3. Mark with RED TAG indicating: Date, Description, Detector\n4. Move to designated NCR holding area"],
            ['Documentation / التوثيق', "1. Quality Controller opens NCR in the system (iso_ncr.php)\n2. Record: Date, product details, defect description, quantity affected\n3. Attach photos of the defect\n4. Classify severity: Critical / Major / Minor"],
            ['Disposition / المعالجة', "• REWORK: Return for correction, re-inspect after rework\n• USE AS IS: Accept with concession (requires customer approval if applicable)\n• DOWNGRADE: Reclassify for alternative use\n• SCRAP: Dispose per waste management procedure\n• RETURN TO SUPPLIER: For incoming material nonconformities"],
            ['Corrective Action / الإجراء التصحيحي', "1. Analyze root cause (5-Why, Fishbone diagram)\n2. Define corrective actions with responsible persons and deadlines\n3. Implement actions and verify effectiveness\n4. Update CAR in the system and close when verified"],
        ],
    ],
    'Corrective & Preventive Action' => [
        'purpose' => 'To define the procedure for implementing corrective and preventive actions to eliminate the causes of nonconformities and prevent recurrence.',
        'purpose_ar' => 'تحديد إجراء تنفيذ الإجراءات التصحيحية والوقائية للقضاء على أسباب عدم المطابقة ومنع تكرارها.',
        'scope' => 'All nonconformities from internal audits, customer complaints, inspections, and process failures.',
        'references' => ['ISO 9001:2015 §10.2 — Nonconformity and corrective action', 'Root Cause Analysis Tools'],
        'sections' => [
            ['Initiation / البدء', "Sources of CAPA:\n• Non-Conformance Reports (NCRs)\n• Internal/External Audit findings\n• Customer complaints\n• Process performance data\n• Management Review actions\n• Risk assessment findings"],
            ['Root Cause Analysis / تحليل السبب الجذري', "1. Define the problem clearly\n2. Contain the immediate issue (correction)\n3. Apply root cause analysis tools:\n   - 5-Why Analysis\n   - Fishbone (Ishikawa) Diagram\n   - Pareto Analysis\n4. Identify the true root cause(s)"],
            ['Action Planning / تخطيط الإجراء', "1. Define corrective/preventive action(s)\n2. Assign responsible person(s)\n3. Set implementation deadline\n4. Determine required resources\n5. Record in CAR form with tracking number"],
            ['Verification & Closure / التحقق والإغلاق', "1. Verify implementation of actions\n2. Evaluate effectiveness (has the problem recurred?)\n3. Update relevant documents/procedures if needed\n4. Close the CAR when effectiveness is confirmed\n5. Report summary in next Management Review"],
        ],
    ],
    'Cutting Procedure' => [
        'purpose' => 'To define the standard operating procedure for fabric cutting operations to ensure accuracy, minimize waste, and maintain quality.',
        'purpose_ar' => 'تحديد إجراء العمل القياسي لعمليات قص القماش لضمان الدقة وتقليل الهدر والحفاظ على الجودة.',
        'scope' => 'All cutting operations in the Cutting Department.',
        'references' => ['Technical Specifications per Order', 'Marker Planning Guidelines', 'Machine Safety Manual'],
        'sections' => [
            ['Preparation / التحضير', "1. Receive approved Tech Pack and marker plan\n2. Verify fabric type, color, and quantity against Production Order\n3. Inspect fabric for defects before spreading\n4. Allow fabric relaxation time (min 12 hours for knits)\n5. Set up cutting table and machine"],
            ['Spreading / الفرش', "1. Align fabric selvedge with table edge\n2. Maintain consistent tension (no stretching)\n3. Check number of plies per the cutting ticket\n4. Ensure all plies in same direction (face up/down per requirement)\n5. Mark end-of-roll positions for tracking"],
            ['Cutting / القص', "1. Place approved marker on fabric stack\n2. Cut along pattern lines using appropriate equipment\n3. Large pieces: straight knife; Small pieces: band saw\n4. Verify critical measurements after cutting\n5. Number and bundle cut pieces by size/color/bundle ticket"],
            ['Quality Check / فحص الجودة', "1. Compare cut pieces against patterns (±3mm tolerance)\n2. Verify size grading accuracy\n3. Check for fraying, notch positions, drill holes\n4. Report any fabric defects discovered during cutting\n5. Record cutting efficiency (actual vs planned)"],
        ],
    ],
    'Sewing Operations' => [
        'purpose' => 'To define the standard operating procedure for garment assembly and sewing to ensure consistent quality and efficiency.',
        'purpose_ar' => 'تحديد إجراء العمل القياسي لتجميع وخياطة الملابس لضمان جودة واتساق الإنتاج.',
        'scope' => 'All sewing lines and operations.',
        'references' => ['Operation Breakdown per Style', 'Machine Settings Guide', 'Quality Check Points'],
        'sections' => [
            ['Line Setup / إعداد الخط', "1. Receive cutting bundles and Tech Pack\n2. Study operation sequence and allocate operators\n3. Set up machines: stitch type, SPI, tension, needle size\n4. Prepare sample/mock-up for line approval\n5. Conduct pre-production meeting with line team"],
            ['Production / الإنتاج', "1. Follow operation sequence as per layout plan\n2. Each operator performs self-inspection before passing\n3. Maintain bundle discipline (no mixing sizes/colors)\n4. Use correct thread colors and seam allowances\n5. Report machine issues immediately to Maintenance"],
            ['Quality Checkpoints / نقاط فحص الجودة', "1. First piece approval by Quality Controller\n2. In-line roving inspection every 2 hours\n3. End-of-line 100% inspection\n4. Critical operations: double-check measurements\n5. Record hourly output and defect rate"],
        ],
    ],
    'Finishing & Packaging' => [
        'purpose' => 'To define the procedure for finishing, pressing, and packaging garments to meet customer specifications.',
        'purpose_ar' => 'تحديد إجراء التوضيب والكي والتغليف لتلبية مواصفات العميل.',
        'scope' => 'All finished garments in the Finishing Department.',
        'references' => ['Customer Packaging Requirements', 'Pressing Guidelines per Fabric', 'Labeling Standards'],
        'sections' => [
            ['Thread Trimming & Cleaning / قص الخيوط والتنظيف', "1. Trim all loose threads (inside and outside)\n2. Remove chalk/marker marks, sticker residues\n3. Lint roll or brush to remove debris\n4. Spot clean minor stains if possible"],
            ['Pressing / الكي', "1. Set iron temperature per fabric type\n2. Press garment following approved pressing sequence\n3. Use press cloths for delicate fabrics\n4. Check for shine marks, scorch marks, water spots\n5. Allow cooling before folding"],
            ['Folding & Packaging / الطي والتغليف', "1. Fold per customer-specified method\n2. Insert tissue paper, cardboard, and pins as required\n3. Apply hang tags, price tags, and barcode labels\n4. Place in polybag (size/style matching)\n5. Pack in cartons per assortment instructions\n6. Apply carton labels and marks"],
        ],
    ],
    'Preventive Maintenance Plan' => [
        'purpose' => 'To define the scheduled preventive maintenance plan for all production equipment to minimize breakdowns and ensure consistent performance.',
        'purpose_ar' => 'تحديد خطة الصيانة الوقائية المجدولة لجميع معدات الإنتاج لتقليل الأعطال وضمان أداء متسق.',
        'scope' => 'All production machinery, cutting equipment, pressing equipment, and utility systems.',
        'references' => ['Equipment Manufacturer Manuals', 'Machine Inventory Register', 'ISO 9001:2015 §7.1.3 — Infrastructure'],
        'sections' => [
            ['Maintenance Schedule / جدول الصيانة', "DAILY:\n• Clean machines, remove lint and thread waste\n• Check oil levels and lubricate moving parts\n• Inspect needle condition and replace as needed\n\nWEEKLY:\n• Check belt tension and alignment\n• Inspect electrical connections\n• Clean bobbin cases and hook assemblies\n\nMONTHLY:\n• Full machine service and calibration\n• Replace worn parts (feed dogs, presser feet)\n• Test safety devices and emergency stops\n\nANNUALLY:\n• Complete overhaul of critical equipment\n• Electrical safety testing\n• Compressor and steam boiler inspection"],
            ['Maintenance Records / سجلات الصيانة', "1. Log all maintenance activities in Machine Maintenance Card\n2. Record date, work performed, parts replaced, technician name\n3. Track machine downtime and breakdown frequency\n4. Analyze trends for replacement planning"],
        ],
    ],
    'Internal Audit Procedure' => [
        'purpose' => 'To define the procedure for planning and conducting internal audits to verify the effectiveness of the QMS.',
        'purpose_ar' => 'تحديد إجراء تخطيط وتنفيذ التدقيق الداخلي للتحقق من فعالية نظام إدارة الجودة.',
        'scope' => 'All processes and departments within the scope of the QMS.',
        'references' => ['ISO 9001:2015 §9.2 — Internal Audit', 'ISO 19011:2018 — Guidelines for Auditing Management Systems'],
        'sections' => [
            ['Audit Planning / تخطيط التدقيق', "1. Prepare Annual Audit Plan covering all QMS processes\n2. Schedule audits considering process importance and previous results\n3. Assign trained, independent auditors (no self-audit)\n4. Prepare audit checklist based on ISO 9001 requirements\n5. Notify auditee minimum 1 week in advance"],
            ['Conducting the Audit / تنفيذ التدقيق', "1. Opening meeting: explain scope, objectives, methodology\n2. Collect evidence: interviews, document review, observation\n3. Record findings: Conformity, Minor NC, Major NC, Observation\n4. Closing meeting: present findings, agree on corrective actions"],
            ['Reporting & Follow-up / التقارير والمتابعة', "1. Issue Audit Report within 5 working days\n2. Auditee submits CAPA plan within 10 working days\n3. Verify corrective action implementation\n4. Report audit summary in Management Review\n5. Archive all audit records for minimum 3 years"],
        ],
    ],
    'Management Review' => [
        'purpose' => 'To define the process for conducting management reviews of the QMS to ensure its continuing suitability, adequacy, and effectiveness.',
        'purpose_ar' => 'تحديد عملية إجراء مراجعة الإدارة لنظام إدارة الجودة لضمان استمرار ملاءمته وكفايته وفعاليته.',
        'scope' => 'Conducted by top management at planned intervals (minimum annually).',
        'references' => ['ISO 9001:2015 §9.3 — Management Review'],
        'sections' => [
            ['Review Inputs / مدخلات المراجعة', "a) Status of actions from previous management reviews\nb) Changes in external and internal issues\nc) QMS performance and effectiveness:\n   - Customer satisfaction and feedback\n   - Quality objectives achievement\n   - Process performance and product conformity\n   - Nonconformities and corrective actions\n   - Monitoring and measurement results\n   - Audit results\n   - Supplier performance\nd) Adequacy of resources\ne) Effectiveness of risk/opportunity actions\nf) Opportunities for improvement"],
            ['Review Outputs / مخرجات المراجعة', "a) Decisions on improvement opportunities\nb) Need for changes to the QMS\nc) Resource needs\nd) Updated quality objectives\ne) Action items with responsibilities and deadlines"],
            ['Documentation / التوثيق', "1. Record meeting minutes with attendees\n2. Document all decisions and action items\n3. Track action completion at next review\n4. Distribute minutes to all relevant managers\n5. Archive for minimum 3 years"],
        ],
    ],
    'Document Control Procedure' => [
        'purpose' => 'To establish the procedure for creating, reviewing, approving, distributing, and controlling documented information within the QMS.',
        'purpose_ar' => 'وضع إجراء إنشاء ومراجعة واعتماد وتوزيع والتحكم في المعلومات الموثقة ضمن نظام إدارة الجودة.',
        'scope' => 'All documented information (policies, procedures, work instructions, forms, records) within the QMS.',
        'references' => ['ISO 9001:2015 §7.5 — Documented Information'],
        'sections' => [
            ['Document Creation / إنشاء الوثيقة', "1. Author drafts document using approved template\n2. Include: document number, title, revision, effective date\n3. Submit for review by process owner\n4. Quality Manager approves the document\n5. Register in Document Control System (iso_docs.php)"],
            ['Distribution & Access / التوزيع والوصول', "1. Distribute approved documents to relevant departments\n2. Remove obsolete versions from circulation\n3. Maintain Master Document List (this register)\n4. Ensure current versions available at point of use\n5. Control external documents as applicable"],
            ['Revision Control / ضبط المراجعات', "1. Changes require same approval as original\n2. Update revision history with change description\n3. Issue new revision, withdraw old version\n4. Notify affected personnel of changes\n5. Retain previous revisions for reference (marked OBSOLETE)"],
            ['Retention & Disposal / الاحتفاظ والإتلاف', "Records retained per Retention Schedule:\n• Quality records: minimum 3 years\n• Customer-related: duration of contract + 2 years\n• Training records: duration of employment + 1 year\n• Audit records: 3 years\n• Disposal: shredding for confidential documents"],
        ],
    ],
    'Supplier Evaluation' => [
        'purpose' => 'To establish the procedure for evaluating, selecting, and monitoring suppliers to ensure externally provided products and services meet requirements.',
        'purpose_ar' => 'وضع إجراء تقييم واختيار ومراقبة الموردين لضمان أن المنتجات والخدمات المقدمة خارجياً تستوفي المتطلبات.',
        'scope' => 'All suppliers of raw materials, accessories, and outsourced services.',
        'references' => ['ISO 9001:2015 §8.4 — Control of externally provided processes', 'Approved Supplier List'],
        'sections' => [
            ['Initial Evaluation / التقييم الأولي', "Criteria for new supplier qualification:\n1. Quality capability (certifications, samples)\n2. Delivery performance history\n3. Price competitiveness\n4. Financial stability\n5. Compliance with ethical/environmental standards\n\nScoring: Rate each criterion 1-5, minimum total score: 15/25"],
            ['Ongoing Monitoring / المراقبة المستمرة', "Quarterly evaluation criteria:\n• Quality: Incoming inspection pass rate (%)\n• Delivery: On-time delivery rate (%)\n• Response: Communication and issue resolution\n• Flexibility: Ability to handle changes/urgent orders\n\nRating Scale:\nA (≥80%) = Approved | B (60-79%) = Conditional | C (<60%) = Under Review"],
            ['Actions / الإجراءات', "• A-rated: Continue, consider for increased volume\n• B-rated: Issue improvement request, re-evaluate in 3 months\n• C-rated: Reduce orders, seek alternatives, possible removal\n• Disqualified: Remove from Approved Supplier List, notify Purchasing"],
        ],
    ],
    'Risk & Opportunity Assessment' => [
        'purpose' => 'To define the methodology for identifying, assessing, and treating risks and opportunities that may affect the QMS and its intended results.',
        'purpose_ar' => 'تحديد منهجية تحديد وتقييم ومعالجة المخاطر والفرص التي قد تؤثر على نظام إدارة الجودة ونتائجه المرجوة.',
        'scope' => 'All processes within the QMS, including external factors and interested party requirements.',
        'references' => ['ISO 9001:2015 §6.1 — Actions to address risks and opportunities', 'ISO 31000:2018 — Risk Management', 'CANDYTEX Risk Register (iso_risk.php)'],
        'sections' => [
            ['Risk Identification / تحديد المخاطر', "Sources for risk identification:\n• Process analysis and SWOT\n• Customer complaints and returns\n• Supplier performance issues\n• Regulatory changes\n• Equipment failures\n• Workforce competency gaps\n• Market and competitive changes"],
            ['Risk Assessment / تقييم المخاطر', "Use 5×5 Risk Matrix:\n• Likelihood (1-5): Rare → Almost Certain\n• Severity (1-5): Negligible → Catastrophic\n• Risk Score = Likelihood × Severity\n\nRisk Levels:\n• 1-4: Low (Accept/Monitor)\n• 5-9: Medium (Monitor/Control)\n• 10-15: High (Urgent Action)\n• 16-25: Critical (Immediate Action)"],
            ['Risk Treatment / معالجة المخاطر', "Options:\n• AVOID: Eliminate the activity causing risk\n• MITIGATE: Reduce likelihood or impact\n• TRANSFER: Insurance, outsourcing\n• ACCEPT: Monitor and review\n\nAll treatments recorded in Risk Register with responsible person and deadline."],
        ],
    ],
];

// Default template for documents without specific content
$default_template = [
    'purpose' => $doc['description'] ?: 'To define and standardize the procedures related to ' . $doc['title_en'] . '.',
    'purpose_ar' => 'تحديد وتوحيد الإجراءات المتعلقة بـ ' . ($doc['title_ar'] ?: $doc['title_en']) . '.',
    'scope' => $doc['department'] ?: 'Relevant departments within CANDYTEX S.A.R.L.',
    'references' => ['ISO 9001:2015', 'Company Quality Manual', 'Related SOPs and Work Instructions'],
    'sections' => [
        ['Procedure / الإجراء', "Define the step-by-step procedure here.\n\n1. ...\n2. ...\n3. ..."],
        ['Responsibilities / المسؤوليات', "Define roles and responsibilities.\n\n• Process Owner: ...\n• Executor: ...\n• Reviewer: ..."],
        ['Records / السجلات', "List the records generated by this procedure:\n\n• ...\n• ..."],
    ],
];

$tpl = $templates[$doc['title_en']] ?? $default_template;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>
        <?= htmlspecialchars($doc['doc_number']) ?> —
        <?= htmlspecialchars($doc['title_en']) ?>
    </title>
    <style>
        @page {
            size: A4;
            margin: 20mm 15mm 20mm 15mm;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #222;
            padding: 20px;
            max-width: 210mm;
            margin: 0 auto;
        }

        .doc-header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            border: 2px solid #1a237e;
        }

        .doc-header-table td {
            padding: 8px 12px;
            border: 1px solid #ccc;
            vertical-align: middle;
        }

        .doc-header-table .logo-cell {
            width: 25%;
            text-align: center;
            background: #f5f6fa;
        }

        .doc-header-table .title-cell {
            width: 50%;
            text-align: center;
        }

        .doc-header-table .meta-cell {
            width: 25%;
            font-size: 9pt;
            background: #f5f6fa;
        }

        .company-name {
            font-size: 14pt;
            font-weight: bold;
            color: #1a237e;
        }

        .doc-title {
            font-size: 13pt;
            font-weight: bold;
            color: #1a237e;
            margin: 4px 0;
        }

        .doc-title-ar {
            font-size: 11pt;
            color: #555;
            direction: rtl;
        }

        .meta-label {
            font-weight: 600;
            color: #555;
        }

        h2 {
            font-size: 12pt;
            color: #1a237e;
            margin: 20px 0 8px;
            padding: 6px 12px;
            background: #e8eaf6;
            border-left: 4px solid #1a237e;
            border-radius: 0 4px 4px 0;
        }

        h3 {
            font-size: 11pt;
            color: #333;
            margin: 14px 0 6px;
        }

        p,
        li {
            font-size: 10.5pt;
            margin-bottom: 4px;
        }

        .section-content {
            padding: 8px 14px;
            white-space: pre-line;
            font-size: 10pt;
            line-height: 1.5;
        }

        .refs {
            list-style: disc;
            padding-left: 20px;
            font-size: 10pt;
        }

        .revision-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 9pt;
        }

        .revision-table th {
            background: #1a237e;
            color: #fff;
            padding: 6px 10px;
            text-align: left;
        }

        .revision-table td {
            padding: 5px 10px;
            border: 1px solid #ddd;
        }

        .revision-table tr:nth-child(even) {
            background: #f9f9fa;
        }

        .approval-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        .approval-table td {
            padding: 12px;
            border: 1px solid #ccc;
            text-align: center;
            width: 33%;
        }

        .approval-table .label {
            font-size: 9pt;
            color: #666;
            margin-bottom: 5px;
        }

        .approval-table .sig-line {
            border-bottom: 1px solid #333;
            margin: 25px auto 5px;
            width: 80%;
        }

        .approval-table .role {
            font-weight: 600;
            font-size: 10pt;
        }

        .footer {
            text-align: center;
            font-size: 8pt;
            color: #888;
            border-top: 1px solid #ccc;
            padding-top: 10px;
            margin-top: 30px;
        }

        .no-print {
            margin: 20px 0;
            text-align: center;
        }

        .btn-print {
            padding: 12px 30px;
            background: #1a237e;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1em;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-print:hover {
            background: #283593;
        }

        .btn-back {
            padding: 12px 30px;
            background: #666;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1em;
            cursor: pointer;
            text-decoration: none;
            margin-left: 10px;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            body {
                padding: 0;
            }

            h2 {
                page-break-after: avoid;
            }

            .section-content {
                page-break-inside: avoid;
            }
        }
    </style>
</head>

<body>

    <div class="no-print">
        <button class="btn-print" onclick="window.print()">🖨️ طباعة الوثيقة — Print Document</button>
        <a href="iso_docs.php" class="btn-back">← العودة للسجل</a>
    </div>

    <!-- ISO Document Header -->
    <table class="doc-header-table">
        <tr>
            <td class="logo-cell" rowspan="3">
                <div class="company-name">🏭 CANDYTEX</div>
                <div style="font-size:8pt;color:#666">S.A.R.L — Excellence in Textiles</div>
                <div style="font-size:7pt;color:#888;margin-top:4px">ISO 9001:2015 Certified</div>
            </td>
            <td class="title-cell" rowspan="3">
                <div class="doc-title">
                    <?= htmlspecialchars($doc['title_en']) ?>
                </div>
                <?php if ($doc['title_ar']): ?>
                    <div class="doc-title-ar">
                        <?= htmlspecialchars($doc['title_ar']) ?>
                    </div>
                <?php endif; ?>
                <div style="font-size:8pt;color:#888;margin-top:4px">
                    <?= htmlspecialchars($doc['category']) ?> —
                    <?= htmlspecialchars($doc['doc_type']) ?>
                </div>
            </td>
            <td class="meta-cell"><span class="meta-label">Doc No:</span>
                <?= htmlspecialchars($doc['doc_number']) ?>
            </td>
        </tr>
        <tr>
            <td class="meta-cell"><span class="meta-label">Revision:</span>
                <?= htmlspecialchars($doc['current_revision']) ?>
            </td>
        </tr>
        <tr>
            <td class="meta-cell"><span class="meta-label">Effective:</span>
                <?= !empty($revisions) ? date('d/m/Y', strtotime(end($revisions)['effective_date'])) : date('d/m/Y') ?>
            </td>
        </tr>
        <tr>
            <td class="meta-cell" style="text-align:center"><span class="meta-label">Factory:</span>
                CANDYTEX S.A.R.L <?= htmlspecialchars($doc['location'] ?? '') ?>
            </td>
            <td class="meta-cell" style="text-align:center"><span class="meta-label">Dept:</span>
                <?= htmlspecialchars($doc['department'] ?: 'All') ?>
            </td>
            <td class="meta-cell"><span class="meta-label">Status:</span>
                <?= htmlspecialchars($doc['status']) ?>
            </td>
        </tr>
        <tr>
            <td class="meta-cell" colspan="3" style="text-align:center"><span class="meta-label">Owner:</span>
                <?= htmlspecialchars($doc['owner'] ?: '-') ?>
            </td>
        </tr>
    </table>

    <!-- 1. Purpose -->
    <h2>1. Purpose / الغرض</h2>
    <div class="section-content">
        <?= nl2br(htmlspecialchars($tpl['purpose'])) ?>
        <br><br>
        <em style="color:#555;direction:rtl;display:block;text-align:right">
            <?= htmlspecialchars($tpl['purpose_ar']) ?>
        </em>
    </div>

    <!-- 2. Scope -->
    <h2>2. Scope / النطاق</h2>
    <div class="section-content">
        <?= nl2br(htmlspecialchars($tpl['scope'])) ?>
    </div>

    <!-- 3. References -->
    <h2>3. References / المراجع</h2>
    <ul class="refs">
        <?php foreach ($tpl['references'] as $ref): ?>
            <li>
                <?= htmlspecialchars($ref) ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- 4+ Procedure Sections -->
    <?php foreach ($tpl['sections'] as $idx => $section): ?>
        <h2>
            <?= ($idx + 4) ?>.
            <?= htmlspecialchars($section[0]) ?>
        </h2>
        <div class="section-content">
            <?= nl2br(htmlspecialchars($section[1])) ?>
        </div>
    <?php endforeach; ?>

    <!-- Revision History -->
    <h2>Revision History / سجل المراجعات</h2>
    <table class="revision-table">
        <thead>
            <tr>
                <th>Rev</th>
                <th>Date</th>
                <th>Description of Change</th>
                <th>Approved By</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($revisions)): ?>
                <tr>
                    <td colspan="4" style="text-align:center;color:#888">No revisions recorded</td>
                </tr>
            <?php else: ?>
                <?php foreach ($revisions as $rv): ?>
                    <tr>
                        <td><strong>
                                <?= htmlspecialchars($rv['revision']) ?>
                            </strong></td>
                        <td>
                            <?= $rv['effective_date'] ? date('d/m/Y', strtotime($rv['effective_date'])) : '-' ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($rv['change_description'] ?: '-') ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($rv['approved_by'] ?: '-') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Approval Signatures -->
    <table class="approval-table">
        <tr>
            <td>
                <div class="label">Prepared By / أعدّ بواسطة</div>
                <div class="sig-line"></div>
                <div class="role">Document Author</div>
                <div style="font-size:8pt;color:#888">Date: ___/___/______</div>
            </td>
            <td>
                <div class="label">Reviewed By / راجع بواسطة</div>
                <div class="sig-line"></div>
                <div class="role">Process Owner</div>
                <div style="font-size:8pt;color:#888">Date: ___/___/______</div>
            </td>
            <td>
                <div class="label">Approved By / اعتمد بواسطة</div>
                <div class="sig-line"></div>
                <div class="role">Quality Manager</div>
                <div style="font-size:8pt;color:#888">Date: ___/___/______</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        <strong>CONFIDENTIAL</strong> — This document is the property of CANDYTEX S.A.R.L. Unauthorized copying or
        distribution is prohibited.<br>
        Printed:
        <?= date('Y-m-d H:i') ?> |
        <?= htmlspecialchars($doc['doc_number']) ?> Rev
        <?= htmlspecialchars($doc['current_revision']) ?> | Page 1 of 1
    </div>

</body>

</html>