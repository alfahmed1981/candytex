<?php
session_start();
require 'db.php';

// Auth is optional for guide viewing, but we'll show personalized info if logged in
$is_logged_in = isset($_SESSION['user_cin']);
$user_name = $is_logged_in ? $_SESSION['user_name'] : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دليل استخدام لوحة SQD+C</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            direction: rtl;
        }

        .guide-container {
            max-width: 900px;
            margin: 20px auto;
            padding: 30px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }

        .guide-header {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border-radius: 12px;
            color: white;
            margin-bottom: 30px;
        }

        .guide-header h1 {
            margin: 0;
            font-size: 2em;
        }

        .guide-header p {
            margin: 10px 0 0;
            opacity: 0.9;
            font-size: 1.1em;
        }

        .section {
            margin-bottom: 30px;
            padding: 25px;
            background: #f8f9fa;
            border-radius: 12px;
            border-right: 5px solid #007bff;
        }

        .section h2 {
            color: #333;
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.4em;
        }

        .section h2 .emoji {
            font-size: 1.3em;
        }

        .step-box {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin: 15px 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-right: 4px solid #28a745;
        }

        .step-number {
            display: inline-flex;
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border-radius: 50%;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1em;
            margin-left: 15px;
        }

        .step-title {
            font-size: 1.2em;
            font-weight: bold;
            color: #333;
        }

        .step-content {
            margin-top: 12px;
            color: #555;
            line-height: 1.8;
        }

        .color-legend {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin: 20px 0;
        }

        .color-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        }

        .color-box {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.2em;
        }

        .color-green {
            background: #28a745;
        }

        .color-orange {
            background: #fd7e14;
        }

        .color-red {
            background: #dc3545;
        }

        .color-blue {
            background: #007bff;
        }

        .color-gray {
            background: #6c757d;
        }

        .color-text {
            font-weight: 600;
            color: #333;
        }

        .color-desc {
            font-size: 0.85em;
            color: #666;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .kpi-card {
            text-align: center;
            padding: 20px;
            border-radius: 12px;
            color: white;
        }

        .kpi-card.safety {
            background: linear-gradient(135deg, #28a745, #20c997);
        }

        .kpi-card.quality {
            background: linear-gradient(135deg, #007bff, #6610f2);
        }

        .kpi-card.delivery {
            background: linear-gradient(135deg, #fd7e14, #ffc107);
        }

        .kpi-card.fives {
            background: linear-gradient(135deg, #17a2b8, #20c997);
        }

        .kpi-card.cost {
            background: linear-gradient(135deg, #dc3545, #fd7e14);
        }

        .kpi-card h3 {
            margin: 0 0 5px;
            font-size: 1.3em;
        }

        .kpi-card p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9em;
        }

        .tip-box {
            background: linear-gradient(135deg, #fff3cd, #ffeeba);
            border-right: 4px solid #ffc107;
            padding: 15px 20px;
            border-radius: 8px;
            margin: 15px 0;
        }

        .tip-box::before {
            content: "💡 نصيحة: ";
            font-weight: bold;
            color: #856404;
        }

        .warning-box {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            border-right: 4px solid #dc3545;
            padding: 15px 20px;
            border-radius: 8px;
            margin: 15px 0;
        }

        .warning-box::before {
            content: "⚠️ تنبيه: ";
            font-weight: bold;
            color: #721c24;
        }

        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .btn {
            padding: 15px 30px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: bold;
            font-size: 1.1em;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
        }

        .screenshot-placeholder {
            background: #e9ecef;
            border: 2px dashed #dee2e6;
            border-radius: 12px;
            padding: 40px;
            text-align: center;
            color: #6c757d;
            margin: 15px 0;
        }

        .quick-links {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .quick-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px;
            background: white;
            border-radius: 10px;
            text-decoration: none;
            color: #333;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .quick-link:hover {
            background: #007bff;
            color: white;
            transform: translateX(-5px);
        }

        .quick-link .icon {
            font-size: 1.5em;
        }

        table.help-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            background: white;
            border-radius: 10px;
            overflow: hidden;
        }

        table.help-table th,
        table.help-table td {
            padding: 12px 15px;
            text-align: right;
            border-bottom: 1px solid #eee;
        }

        table.help-table th {
            background: #007bff;
            color: white;
        }

        table.help-table tr:hover {
            background: #f8f9fa;
        }

        @media (max-width: 768px) {
            .guide-container {
                margin: 10px;
                padding: 20px;
            }

            .color-legend {
                grid-template-columns: 1fr;
            }

            .kpi-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>

<body>
    <div class="guide-container">
        <!-- Header -->
        <div class="guide-header">
            <h1>📊 دليل استخدام لوحة SQD+C</h1>
            <p>دليل سهل ومبسط لرؤساء الفرق لملء لوحة المتابعة اليومية</p>
            <?php if ($is_logged_in): ?>
                <p style="margin-top:15px; font-size:0.9em;">👋 مرحباً
                    <?php echo htmlspecialchars($user_name); ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- Quick Links -->
        <div class="section" style="border-right-color: #28a745;">
            <h2><span class="emoji">🔗</span> روابط سريعة</h2>
            <div class="quick-links">
                <a href="index.php" class="quick-link">
                    <span class="icon">📊</span>
                    <span>لوحة المتابعة الرئيسية</span>
                </a>
                <a href="my_team.php" class="quick-link">
                    <span class="icon">👥</span>
                    <span>إدارة الفريق</span>
                </a>
                <a href="#how-to-login" class="quick-link">
                    <span class="icon">🔐</span>
                    <span>كيفية الدخول</span>
                </a>
                <a href="#fill-board" class="quick-link">
                    <span class="icon">✏️</span>
                    <span>كيفية ملء اللوحة</span>
                </a>
            </div>
        </div>

        <!-- What is SQDC -->
        <div class="section" style="border-right-color: #17a2b8;">
            <h2><span class="emoji">❓</span> ما هي لوحة SQD+C؟</h2>
            <div class="step-content">
                <p>لوحة <strong>SQD+C</strong> هي أداة للمتابعة اليومية لأداء الفريق. كل يوم تسجل حالة العمل في 5
                    مجالات:</p>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card safety">
                    <h3>S - السلامة</h3>
                    <p>SAFETY</p>
                    <p style="font-size:0.8em; margin-top:8px;">هل وقع حادث؟</p>
                </div>
                <div class="kpi-card quality">
                    <h3>Q - الجودة</h3>
                    <p>QUALITY</p>
                    <p style="font-size:0.8em; margin-top:8px;">هل توجد عيوب؟</p>
                </div>
                <div class="kpi-card delivery">
                    <h3>D - التسليم</h3>
                    <p>DELIVERY</p>
                    <p style="font-size:0.8em; margin-top:8px;">هل تم التسليم في الوقت؟</p>
                </div>
                <div class="kpi-card fives">
                    <h3>+ - التحسين (5S)</h3>
                    <p>5S / IMPROVEMENT</p>
                    <p style="font-size:0.8em; margin-top:8px;">النظافة والتنظيم</p>
                </div>
                <div class="kpi-card cost">
                    <h3>C - التكلفة</h3>
                    <p>COST</p>
                    <p style="font-size:0.8em; margin-top:8px;">هل تجاوزنا الميزانية؟</p>
                </div>
            </div>
        </div>

        <!-- How to Login -->
        <div class="section" id="how-to-login" style="border-right-color: #6610f2;">
            <h2><span class="emoji">🔐</span> الخطوة 1: كيفية الدخول للنظام</h2>

            <div class="step-box">
                <span class="step-number">1</span>
                <span class="step-title">افتح صفحة الدخول</span>
                <div class="step-content">
                    <p>اذهب إلى رابط اللوحة: <a href="index.php" style="color:#007bff;">📊 index.php</a></p>
                </div>
            </div>

            <div class="step-box">
                <span class="step-number">2</span>
                <span class="step-title">أدخل بياناتك</span>
                <div class="step-content">
                    <table class="help-table">
                        <tr>
                            <th>الحقل</th>
                            <th>ماذا تكتب؟</th>
                            <th>مثال</th>
                        </tr>
                        <tr>
                            <td><strong>رقم البطاقة (CIN)</strong></td>
                            <td>رقم بطاقتك الوطنية</td>
                            <td>AB123456</td>
                        </tr>
                        <tr>
                            <td><strong>رقم الهاتف</strong></td>
                            <td>رقمك المسجل في النظام</td>
                            <td>0612345678</td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="step-box">
                <span class="step-number">3</span>
                <span class="step-title">اضغط "دخول"</span>
                <div class="step-content">
                    <p>بعد إدخال البيانات الصحيحة، ستدخل مباشرة إلى لوحة المتابعة الخاصة بك.</p>
                </div>
            </div>

            <div class="tip-box">
                إذا ظهرت رسالة خطأ، تأكد أن رقم الهاتف هو نفسه المسجل في ملفك. اتصل بالمسؤول إذا نسيت بياناتك.
            </div>
        </div>

        <!-- How to Fill the Board -->
        <div class="section" id="fill-board" style="border-right-color: #28a745;">
            <h2><span class="emoji">✏️</span> الخطوة 2: كيفية ملء اللوحة اليومية</h2>

            <div class="step-box">
                <span class="step-number">1</span>
                <span class="step-title">اختر اليوم المناسب</span>
                <div class="step-content">
                    <p>كل عمود يمثل مؤشر (S, Q, D, +, C)، وكل مربع صغير يمثل يوم من الشهر (1 إلى 31).</p>
                    <p><strong>اضغط على المربع</strong> الذي يمثل اليوم الذي تريد تسجيله.</p>
                </div>
            </div>

            <div class="step-box">
                <span class="step-number">2</span>
                <span class="step-title">اختر الحالة المناسبة</span>
                <div class="step-content">
                    <p>عند الضغط على يوم، ستظهر لك نافذة لاختيار الحالة:</p>

                    <div class="color-legend">
                        <div class="color-item">
                            <div class="color-box color-green">✓</div>
                            <div>
                                <div class="color-text">أخضر - ممتاز</div>
                                <div class="color-desc">تم تحقيق الهدف ✅</div>
                            </div>
                        </div>
                        <div class="color-item">
                            <div class="color-box color-orange">!</div>
                            <div>
                                <div class="color-text">برتقالي - انتباه</div>
                                <div class="color-desc">يحتاج إجراء ⚠️</div>
                            </div>
                        </div>
                        <div class="color-item">
                            <div class="color-box color-red">✗</div>
                            <div>
                                <div class="color-text">أحمر - مشكلة</div>
                                <div class="color-desc">لم يتحقق الهدف / حادث ❌</div>
                            </div>
                        </div>
                        <div class="color-item">
                            <div class="color-box color-blue">~</div>
                            <div>
                                <div class="color-text">أزرق - عطلة</div>
                                <div class="color-desc">يوم عطلة / لا ينطبق 📅</div>
                            </div>
                        </div>
                        <div class="color-item">
                            <div class="color-box color-gray">○</div>
                            <div>
                                <div class="color-text">رمادي - فارغ</div>
                                <div class="color-desc">لم يتم التسجيل بعد</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="step-box">
                <span class="step-number">3</span>
                <span class="step-title">سيتم الحفظ تلقائياً</span>
                <div class="step-content">
                    <p>بمجرد اختيار اللون، <strong>يتم الحفظ تلقائياً</strong> في النظام. لا تحتاج للضغط على زر حفظ!</p>
                </div>
            </div>

            <div class="warning-box">
                يجب ملء اللوحة <strong>يومياً</strong> في نهاية كل يوم عمل. لا تترك أيام فارغة!
            </div>
        </div>

        <!-- Understanding Each KPI -->
        <div class="section" style="border-right-color: #fd7e14;">
            <h2><span class="emoji">📖</span> شرح كل مؤشر بالتفصيل</h2>

            <table class="help-table">
                <thead>
                    <tr>
                        <th>المؤشر</th>
                        <th>السؤال الذي تجيب عليه</th>
                        <th>متى تضع أخضر؟</th>
                        <th>متى تضع أحمر؟</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong style="color:#28a745;">S - السلامة</strong></td>
                        <td>هل وقعت إصابات أو حوادث اليوم؟</td>
                        <td>لا حوادث ولا إصابات ✅</td>
                        <td>وقع حادث أو إصابة ❌</td>
                    </tr>
                    <tr>
                        <td><strong style="color:#007bff;">Q - الجودة</strong></td>
                        <td>هل المنتجات بجودة عالية؟</td>
                        <td>لا عيوب ولا شكاوى ✅</td>
                        <td>عيوب كثيرة أو شكاوى ❌</td>
                    </tr>
                    <tr>
                        <td><strong style="color:#fd7e14;">D - التسليم</strong></td>
                        <td>هل سلمنا في الموعد المحدد؟</td>
                        <td>كل الطلبات سلمت في الوقت ✅</td>
                        <td>تأخرنا في التسليم ❌</td>
                    </tr>
                    <tr>
                        <td><strong style="color:#17a2b8;">+ - التحسين (5S)</strong></td>
                        <td>هل المكان نظيف ومنظم؟</td>
                        <td>كل شيء مرتب ✅</td>
                        <td>فوضى وعدم تنظيم ❌</td>
                    </tr>
                    <tr>
                        <td><strong style="color:#dc3545;">C - التكلفة</strong></td>
                        <td>هل تجاوزنا الميزانية؟</td>
                        <td>ضمن الميزانية ✅</td>
                        <td>تجاوزنا التكاليف ❌</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Countermeasures -->
        <div class="section" style="border-right-color: #dc3545;">
            <h2><span class="emoji">🛠️</span> الخطوة 3: إضافة إجراء تصحيحي (Counter Measure)</h2>

            <div class="step-content">
                <p>عندما تضع <strong>لون أحمر أو برتقالي</strong>، يجب أن تضيف إجراء تصحيحي لشرح المشكلة وكيف ستحلها.
                </p>
            </div>

            <div class="step-box">
                <span class="step-number">1</span>
                <span class="step-title">اضغط على "+ Add Issue"</span>
                <div class="step-content">
                    <p>في أسفل الصفحة، اضغط على زر <strong>"+ Add Issue / إضافة مشكلة"</strong></p>
                </div>
            </div>

            <div class="step-box">
                <span class="step-number">2</span>
                <span class="step-title">املأ المعلومات المطلوبة</span>
                <div class="step-content">
                    <table class="help-table">
                        <tr>
                            <th>الحقل</th>
                            <th>الشرح</th>
                            <th>مثال</th>
                        </tr>
                        <tr>
                            <td><strong>الفئة (Cat)</strong></td>
                            <td>اختر المؤشر المعني (S, Q, D, +, C)</td>
                            <td>Q (الجودة)</td>
                        </tr>
                        <tr>
                            <td><strong>المشكلة (Issue)</strong></td>
                            <td>اشرح المشكلة بوضوح</td>
                            <td>تم اكتشاف 10 قطع معيبة</td>
                        </tr>
                        <tr>
                            <td><strong>الإجراء (Action)</strong></td>
                            <td>ما الذي ستفعله لحل المشكلة؟</td>
                            <td>فحص الآلة وإصلاحها</td>
                        </tr>
                        <tr>
                            <td><strong>المسؤول (Who)</strong></td>
                            <td>من سيقوم بالإجراء؟</td>
                            <td>أحمد - قسم الصيانة</td>
                        </tr>
                        <tr>
                            <td><strong>الموعد (Due Date)</strong></td>
                            <td>متى سيتم الانتهاء؟</td>
                            <td>2026-02-05</td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="tip-box">
                كلما كان الشرح واضحاً ومفصلاً، كان أفضل لمتابعة المشاكل وحلها.
            </div>
        </div>

        <!-- FAQ -->
        <div class="section" style="border-right-color: #6c757d;">
            <h2><span class="emoji">❔</span> أسئلة شائعة</h2>

            <div class="step-box" style="border-right-color: #17a2b8;">
                <span class="step-title">❓ نسيت رقم الهاتف المسجل، ماذا أفعل؟</span>
                <div class="step-content">
                    <p>اتصل بمسؤول الموارد البشرية أو المدير ليعطيك الرقم الصحيح.</p>
                </div>
            </div>

            <div class="step-box" style="border-right-color: #17a2b8;">
                <span class="step-title">❓ كيف أغير لون يوم سجلته بالخطأ؟</span>
                <div class="step-content">
                    <p>اضغط على نفس اليوم مرة أخرى واختر اللون الصحيح. سيتم تحديثه تلقائياً.</p>
                </div>
            </div>

            <div class="step-box" style="border-right-color: #17a2b8;">
                <span class="step-title">❓ هل يمكنني تعديل أيام قديمة؟</span>
                <div class="step-content">
                    <p>نعم، يمكنك تغيير أي يوم من الشهر الحالي. للأشهر السابقة، استخدم فلتر الشهر والسنة.</p>
                </div>
            </div>

            <div class="step-box" style="border-right-color: #17a2b8;">
                <span class="step-title">❓ من يرى البيانات التي أسجلها؟</span>
                <div class="step-content">
                    <p>أنت والمدير فقط. كل رئيس فريق يرى بياناته الخاصة.</p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="btn-group">
            <a href="index.php" class="btn btn-primary">
                <span>🚀</span>
                <span>ابدأ الآن - دخول اللوحة</span>
            </a>
            <a href="my_team.php" class="btn btn-secondary">
                <span>👥</span>
                <span>إدارة فريقي</span>
            </a>
        </div>

        <!-- Footer -->
        <div style="text-align:center; margin-top:40px; padding-top:20px; border-top:1px solid #eee; color:#666;">
            <p>📞 للمساعدة، اتصل بقسم الموارد البشرية أو المدير المباشر</p>
            <p style="font-size:0.9em;">OPEXA Management Group - SQD+C Digital System</p>
        </div>
    </div>
</body>

</html>