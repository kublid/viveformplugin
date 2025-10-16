<?php
/**
 * Plugin Name: vIVe Hydration Intake
 * Description: Intake & consent with e-sign, SQL storage, admin view/edit/provider-sign + Full Form Builder (sections + all questions), Tools (export/import/backups), and GitHub auto-updates.
 * Version: 1.6.0
 * Author: vIVe / Captain D + Val
 * Text Domain: vive-intake
 */

if (!defined('ABSPATH')) exit;

class Vive_Intake_Plugin {
    // Nonces / options
    const NONCE_ACTION   = 'vive_intake_submit';
    const NONCE_NAME     = 'vive_intake_nonce';
    const OPTION_VERSION = 'vive_intake_version';

    // Admin actions
    const ADMIN_EDIT_ACTION   = 'vive_intake_admin_edit';
    const ADMIN_SIGN_ACTION   = 'vive_intake_admin_sign';
    const ADMIN_BUILDER_SAVE  = 'vive_intake_builder_save';

    // Builder options
    const OPTION_SECTIONS = 'vive_sections';       // [] of sections
    const OPTION_CORE     = 'vive_core_fields';    // [] of core field configs
    const OPTION_CUSTOM   = 'vive_dynamic_fields'; // [] of custom field configs

    // Tools actions & backups
    const ADMIN_TOOLS_EXPORT = 'vive_intake_tools_export';
    const ADMIN_TOOLS_IMPORT = 'vive_intake_tools_import';
    const ADMIN_TOOLS_RESTORE = 'vive_intake_tools_restore';
    const OPTION_BACKUPS = 'vive_config_backups'; // array of timestamped bundles

    // Capability
    private $CAP = 'edit_posts'; // change to 'manage_options' to restrict to Admins

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('admin_init', [$this, 'maybe_upgrade']);

        add_shortcode('vive_intake_form', [$this, 'shortcode']);
        add_action('admin_post_nopriv_vive_intake_submit', [$this, 'handle_submit']);
        add_action('admin_post_vive_intake_submit',        [$this, 'handle_submit']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_' . self::ADMIN_EDIT_ACTION,  [$this, 'handle_admin_edit']);
        add_action('admin_post_' . self::ADMIN_SIGN_ACTION,  [$this, 'handle_admin_sign']);
        add_action('admin_post_' . self::ADMIN_BUILDER_SAVE, [$this, 'handle_builder_save']);

        // Tools submenu + handlers
        add_action('admin_menu', function(){
            add_submenu_page('vive-intakes','Tools (Export/Import)','Tools',$this->CAP,'vive-intakes-tools',[$this,'tools_page']);
        });
        add_action('admin_post_' . self::ADMIN_TOOLS_EXPORT,  [$this,'handle_tools_export']);
        add_action('admin_post_' . self::ADMIN_TOOLS_IMPORT,  [$this,'handle_tools_import']);
        add_action('admin_post_' . self::ADMIN_TOOLS_RESTORE, [$this,'handle_tools_restore']);
    }

    /** ===== DB ===== */
    private function table_name(){ global $wpdb; return $wpdb->prefix . 'vive_intakes'; }

    public function activate(){
        $this->create_or_update_table('1.6.0');
        $this->seed_builder_defaults(false);
        $this->run_migrations('1.6.0');
    }

    public function maybe_upgrade(){
        $cur = get_option(self::OPTION_VERSION);
        if ($cur !== '1.6.0') {
            $this->create_or_update_table('1.6.0');
            $this->run_migrations('1.6.0', $cur);
        }
    }

    private function create_or_update_table($target_version){
        global $wpdb; $table = $this->table_name();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE IF NOT EXISTS `$table` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(255) NULL,

            full_name VARCHAR(255) NOT NULL,
            dob DATE NOT NULL,
            sex_at_birth VARCHAR(20) NULL,
            pronouns VARCHAR(60) NULL,
            phone VARCHAR(40) NULL,
            sms_opt_in TINYINT(1) DEFAULT 0,
            email VARCHAR(255) NULL,
            email_hipaa_opt_in TINYINT(1) DEFAULT 0,
            address1 VARCHAR(255) NULL,
            city VARCHAR(120) NULL,
            state VARCHAR(60) NULL,
            zip VARCHAR(20) NULL,
            emergency_name VARCHAR(255) NULL,
            emergency_relationship VARCHAR(120) NULL,
            emergency_phone VARCHAR(40) NULL,

            medical_flags LONGTEXT NULL,
            allergies LONGTEXT NULL,
            meds_rx LONGTEXT NULL,
            meds_otc LONGTEXT NULL,
            on_anticoagulants VARCHAR(10) NULL,
            on_diuretics VARCHAR(10) NULL,
            status_nvd VARCHAR(3) NULL,
            status_alcohol VARCHAR(3) NULL,
            last_oral_intake LONGTEXT NULL,
            prior_iv_nad LONGTEXT NULL,

            access_preference VARCHAR(20) NULL,
            hard_stick VARCHAR(3) NULL,
            allow_numbing VARCHAR(3) NULL,

            vital_bp VARCHAR(20) NULL,
            vital_hr SMALLINT NULL,
            vital_temp_f DECIMAL(4,1) NULL,
            vital_o2 TINYINT NULL,

            therapy_selection VARCHAR(60) NULL,
            therapy_other VARCHAR(255) NULL,
            nad_dose VARCHAR(10) NULL,
            addons LONGTEXT NULL,
            addons_other VARCHAR(255) NULL,

            consent_treatment TINYINT(1) DEFAULT 0,
            consent_financial TINYINT(1) DEFAULT 0,
            consent_comms_email TINYINT(1) DEFAULT 0,
            consent_comms_sms TINYINT(1) DEFAULT 0,
            consent_privacy TINYINT(1) DEFAULT 0,
            consent_photo TINYINT(1) DEFAULT 0,

            signature_name VARCHAR(255) NULL,
            signature_url TEXT NULL,
            signed_at DATETIME NULL,

            provider_name VARCHAR(255) NULL,
            provider_signature_url TEXT NULL,
            provider_signed_at DATETIME NULL,

            extra_json LONGTEXT NULL,

            honeypot VARCHAR(100) NULL,

            PRIMARY KEY (id)
        ) $charset;";
        dbDelta($sql);
        update_option(self::OPTION_VERSION, $target_version);
    }

    /** ===== Migrations ===== */
    private function run_migrations($to, $from = null){
        $map = $this->migrations();
        uksort($map, 'version_compare');
        foreach ($map as $ver => $callable) {
            if ($from && version_compare($from, $ver, '>=')) continue;
            if (version_compare($to, $ver, '>=')) {
                if (is_callable($callable)) call_user_func($callable);
            }
        }
        update_option(self::OPTION_VERSION, $to);
    }

    private function migrations(){
        return [
            '1.4.0' => function(){ /* ensured extra_json existed in v1.4.0 (handled via dbDelta) */ },
            '1.5.0' => function(){ /* v1.5.0 introduced sections/core/custom options */ },
            '1.6.0' => function(){
                if (get_option(self::OPTION_BACKUPS, null) === null) add_option(self::OPTION_BACKUPS, []);
                foreach ([self::OPTION_SECTIONS,self::OPTION_CORE,self::OPTION_CUSTOM] as $opt) {
                    $val = get_option($opt, []);
                    if (!is_array($val)) update_option($opt, []);
                }
            },
        ];
    }

    /** ===== Builder defaults ===== */
    private function seed_builder_defaults($only_if_empty = true){
        $sections = [
            ['id'=>'patient','title'=>'Patient Information','enabled'=>1,'order'=>10],
            ['id'=>'medical','title'=>'Medical History & Screening','enabled'=>1,'order'=>20],
            ['id'=>'access','title'=>'IV Access Preferences','enabled'=>1,'order'=>30],
            ['id'=>'vitals','title'=>'Vitals (staff use)','enabled'=>0,'order'=>40],
            ['id'=>'therapy','title'=>'IV Menu','enabled'=>1,'order'=>50],
            ['id'=>'custom','title'=>'Additional Questions','enabled'=>1,'order'=>60],
            ['id'=>'consent','title'=>'Consents & Signature','enabled'=>1,'order'=>70],
        ];
        if (!$only_if_empty || get_option(self::OPTION_SECTIONS, null) === null) {
            update_option(self::OPTION_SECTIONS, $sections);
        }

        $core = [
            // Patient
            ['key'=>'full_name','label'=>'Full Name','section'=>'patient','type'=>'text','required'=>1,'enabled'=>1,'order'=>10,'lock_required'=>1,'lock_enabled'=>1],
            ['key'=>'dob','label'=>'Date of Birth','section'=>'patient','type'=>'date','required'=>1,'enabled'=>1,'order'=>20,'lock_required'=>1,'lock_enabled'=>1],
            ['key'=>'sex_at_birth','label'=>'Sex at Birth','section'=>'patient','type'=>'select','options'=>'Male,Female,Intersex,Prefer not to say','required'=>0,'enabled'=>1,'order'=>30],
            ['key'=>'pronouns','label'=>'Pronouns','section'=>'patient','type'=>'text','required'=>0,'enabled'=>0,'order'=>40],
            ['key'=>'phone','label'=>'Mobile Phone','section'=>'patient','type'=>'tel','required'=>0,'enabled'=>1,'order'=>50],
            ['key'=>'sms_opt_in','label'=>'SMS updates opt-in','section'=>'patient','type'=>'checkbox_one','required'=>0,'enabled'=>1,'order'=>60],
            ['key'=>'email','label'=>'Email','section'=>'patient','type'=>'email','required'=>0,'enabled'=>1,'order'=>70],
            ['key'=>'email_hipaa_opt_in','label'=>'Email communications opt-in','section'=>'patient','type'=>'checkbox_one','required'=>0,'enabled'=>1,'order'=>80],
            ['key'=>'address1','label'=>'Address','section'=>'patient','type'=>'text','required'=>0,'enabled'=>1,'order'=>90],
            ['key'=>'city','label'=>'City','section'=>'patient','type'=>'text','required'=>0,'enabled'=>1,'order'=>100],
            ['key'=>'state','label'=>'State','section'=>'patient','type'=>'text','required'=>0,'enabled'=>1,'order'=>110],
            ['key'=>'zip','label'=>'ZIP','section'=>'patient','type'=>'text','required'=>0,'enabled'=>1,'order'=>120],
            ['key'=>'emergency_name','label'=>'Emergency Contact Name','section'=>'patient','type'=>'text','required'=>0,'enabled'=>1,'order'=>130],
            ['key'=>'emergency_relationship','label'=>'Emergency Relationship','section'=>'patient','type'=>'text','required'=>0,'enabled'=>1,'order'=>140],
            ['key'=>'emergency_phone','label'=>'Emergency Contact Phone','section'=>'patient','type'=>'tel','required'=>0,'enabled'=>1,'order'=>150],

            // Medical
            ['key'=>'medical_flags','label'=>'Medical Conditions','section'=>'medical','type'=>'medical_flags','required'=>0,'enabled'=>1,'order'=>10],
            ['key'=>'allergies','label'=>'Allergies','section'=>'medical','type'=>'textarea','required'=>0,'enabled'=>1,'order'=>20],
            ['key'=>'meds_rx','label'=>'Current prescription medications','section'=>'medical','type'=>'textarea','required'=>0,'enabled'=>1,'order'=>30],
            ['key'=>'meds_otc','label'=>'OTC & supplements','section'=>'medical','type'=>'textarea','required'=>0,'enabled'=>1,'order'=>40],
            ['key'=>'on_anticoagulants','label'=>'On blood thinners?','section'=>'medical','type'=>'select','options'=>'Yes,No,Unsure','required'=>0,'enabled'=>1,'order'=>50],
            ['key'=>'on_diuretics','label'=>'On diuretics?','section'=>'medical','type'=>'select','options'=>'Yes,No,Unsure','required'=>0,'enabled'=>0,'order'=>55],
            ['key'=>'status_nvd','label'=>'Nausea/vomiting/diarrhea in last 24 hrs?','section'=>'medical','type'=>'select','options'=>'Yes,No','required'=>0,'enabled'=>1,'order'=>60],
            ['key'=>'status_alcohol','label'=>'Alcohol in last 24 hrs?','section'=>'medical','type'=>'select','options'=>'Yes,No','required'=>0,'enabled'=>0,'order'=>70],
            ['key'=>'last_oral_intake','label'=>'Last oral fluids / food','section'=>'medical','type'=>'textarea','required'=>0,'enabled'=>1,'order'=>80],
            ['key'=>'prior_iv_nad','label'=>'Prior IV hydration or NAD+ (response?)','section'=>'medical','type'=>'textarea','required'=>0,'enabled'=>1,'order'=>90],

            // Access
            ['key'=>'access_preference','label'=>'Preferred arm','section'=>'access','type'=>'select','options'=>'Left,Right,No preference','required'=>0,'enabled'=>1,'order'=>10],
            ['key'=>'hard_stick','label'=>'“Hard stick” history','section'=>'access','type'=>'select','options'=>'Yes,No','required'=>0,'enabled'=>1,'order'=>20],
            ['key'=>'allow_numbing','label'=>'Okay to use topical numbing per protocol','section'=>'access','type'=>'select','options'=>'Yes,No','required'=>0,'enabled'=>1,'order'=>30],

            // Vitals
            ['key'=>'vital_bp','label'=>'BP','section'=>'vitals','type'=>'text','required'=>0,'enabled'=>1,'order'=>10],
            ['key'=>'vital_hr','label'=>'HR','section'=>'vitals','type'=>'number','required'=>0,'enabled'=>1,'order'=>20],
            ['key'=>'vital_temp_f','label'=>'Temp (°F)','section'=>'vitals','type'=>'number','required'=>0,'enabled'=>1,'order'=>30],
            ['key'=>'vital_o2','label'=>'O₂ Sat (%)','section'=>'vitals','type'=>'number','required'=>0,'enabled'=>1,'order'=>40],

            // Therapy
            ['key'=>'therapy_selection','label'=>'Therapy selection','section'=>'therapy','type'=>'select','options'=>'Ultimate Hangover Cure,Mega Myers,Immunity Boost,Energy & Performance,Beauty / Glow,Gentlemen’s Vitality,Glowing Bride,Beach Body,NAD+,Other','required'=>0,'enabled'=>1,'order'=>10],
            ['key'=>'nad_dose','label'=>'NAD+ dose (if selected)','section'=>'therapy','type'=>'select','options'=>'250 mg,500 mg,750 mg,1000 mg','required'=>0,'enabled'=>1,'order'=>20],
            ['key'=>'therapy_other','label'=>'If \"Other\", specify','section'=>'therapy','type'=>'text','required'=>0,'enabled'=>1,'order'=>30],
            ['key'=>'addons','label'=>'Add-ons','section'=>'therapy','type'=>'checkbox_multi','options'=>'B12,Zinc,Glutathione,Taurine,Magnesium,Anti-nausea per protocol,Anti-inflammatory per protocol','required'=>0,'enabled'=>1,'order'=>40],
            ['key'=>'addons_other','label'=>'Add-on (Other)','section'=>'therapy','type'=>'text','required'=>0,'enabled'=>1,'order'=>50],

            // Consents (locked)
            ['key'=>'consent_treatment','label'=>'I agree to treatment consent','section'=>'consent','type'=>'checkbox_one','required'=>1,'enabled'=>1,'order'=>10,'lock_required'=>1,'lock_enabled'=>1],
            ['key'=>'consent_financial','label'=>'I agree to financial policy','section'=>'consent','type'=>'checkbox_one','required'=>1,'enabled'=>1,'order'=>20,'lock_required'=>1,'lock_enabled'=>1],
            ['key'=>'consent_privacy','label'=>'I acknowledge privacy policy','section'=>'consent','type'=>'checkbox_one','required'=>1,'enabled'=>1,'order'=>30,'lock_required'=>1,'lock_enabled'=>1],
            ['key'=>'consent_comms_email','label'=>'Email communications (optional)','section'=>'consent','type'=>'checkbox_one','required'=>0,'enabled'=>1,'order'=>40],
            ['key'=>'consent_comms_sms','label'=>'SMS communications (optional)','section'=>'consent','type'=>'checkbox_one','required'=>0,'enabled'=>1,'order'=>50],
            ['key'=>'consent_photo','label'=>'Photo/social consent (optional)','section'=>'consent','type'=>'checkbox_one','required'=>0,'enabled'=>1,'order'=>60],
            ['key'=>'signature_name','label'=>'Signature (type full name)','section'=>'consent','type'=>'text','required'=>1,'enabled'=>1,'order'=>70,'lock_required'=>1,'lock_enabled'=>1],
            ['key'=>'signature_draw','label'=>'E-Signature (draw)','section'=>'consent','type'=>'signature','required'=>1,'enabled'=>1,'order'=>80,'lock_required'=>1,'lock_enabled'=>1],
        ];
        if (!$only_if_empty || get_option(self::OPTION_CORE, null) === null) update_option(self::OPTION_CORE, $core);
        if ($only_if_empty && get_option(self::OPTION_CUSTOM, null) === null) add_option(self::OPTION_CUSTOM, []);
    }

    /** ===== Admin UI ===== */
    public function admin_menu(){
        add_menu_page('vIVe Intakes','vIVe Intakes',$this->CAP,'vive-intakes',[$this,'admin_page'],'dashicons-forms',26);
        add_submenu_page('vive-intakes','Form Builder','Form Builder',$this->CAP,'vive-intakes-builder',[$this,'builder_page']);
    }

    public function admin_page(){
        if (!current_user_can($this->CAP)) wp_die('Insufficient permissions.');
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        $id     = isset($_GET['id']) ? intval($_GET['id']) : 0;
        echo '<div class="wrap">';
        if ($action === 'edit' && $id) $this->render_admin_edit($id);
        else $this->render_admin_list();
        echo '</div>';
    }

    private function render_admin_list(){
        global $wpdb; $table=$this->table_name();
        $items=$wpdb->get_results("SELECT id, created_at, full_name, dob, phone, email, therapy_selection FROM $table ORDER BY id DESC LIMIT 500");
        echo '<h1>vIVe Intake Submissions</h1>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Date</th><th>Name</th><th>DOB</th><th>Phone</th><th>Email</th><th>Therapy</th><th>Actions</th></tr></thead><tbody>';
        if ($items) foreach($items as $r){
            echo '<tr><td>'.esc_html($r->id).'</td><td>'.esc_html($r->created_at).'</td><td>'.esc_html($r->full_name).'</td><td>'.esc_html($r->dob).'</td><td>'.esc_html($r->phone).'</td><td>'.esc_html($r->email).'</td><td>'.esc_html($r->therapy_selection).'</td><td><a class="button button-small" href="'.esc_url(admin_url('admin.php?page=vive-intakes&action=edit&id='.$r->id)).'">View / Edit / Sign</a></td></tr>';
        } else echo '<tr><td colspan="8">No submissions yet.</td></tr>';
        echo '</tbody></table>';
    }

    /** ===== Shortcode & public form ===== */
    public function shortcode(){
        $errors = !empty($_GET['errors']) ? explode('|', sanitize_text_field($_GET['errors'])) : [];
        $submitted = isset($_GET['submitted']); ob_start();
        if ($submitted) { $ref = isset($_GET['ref']) ? intval($_GET['ref']) : null;
            echo '<div class="vive-intake-thanks"><h2>Thanks!</h2><p>Your intake has been submitted successfully.</p>';
            if ($ref) echo '<p><em>Reference:</em> #'.esc_html($ref).'</p>';
            echo '</div>';
        } else { $this->render_public_form($errors); }
        return ob_get_clean();
    }

    private function render_public_form($errors){
        $action = esc_url(admin_url('admin-post.php'));
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $sections = $this->get_sections(true);
        $core = $this->get_core(true);
        $custom_grouped = $this->get_custom_grouped();
        ?>
        <style>
            .vive-intake {max-width: 860px; margin:0 auto;}
            .vive-grid-1 {display:grid; grid-template-columns:1fr; gap:14px;}
            .vive-box {padding:14px; border:1px solid #e5e5e5; border-radius:8px; background:#fafafa; margin-bottom:14px;}
            .vive-errors {background:#fff0f0;border:1px solid #f5c2c7;color:#842029;padding:10px;margin-bottom:10px;border-radius:6px;}
            label {display:block; font-weight:600; margin-bottom:4px;}
            input[type=text],input[type=date],input[type=email],input[type=tel],input[type=number],textarea,select {width:100%;padding:10px;border:1px solid #ccc;border-radius:6px;background:#fff;}
            .vive-help {font-size:12px;color:#666;}
            canvas.vive-sign {border:1px dashed #aaa;border-radius:6px;background:#fff;width:100%;height:180px;}
            .vive-sign-wrap {display:flex;gap:10px;align-items:center;}
            .vive-sign-wrap button {padding:8px 10px;border:1px solid #ccc;border-radius:6px;background:#f7f7f7;cursor:pointer;}
        </style>
        <div class="vive-intake">
            <h2>vIVe Hydration — Intake & Consent</h2>
            <?php if ($errors) { echo '<div class="vive-errors"><strong>Please fix the following:</strong><ul>'; foreach($errors as $e){ echo '<li>'.esc_html($e).'</li>'; } echo '</ul></div>'; } ?>
            <form method="POST" action="<?php echo $action; ?>">
                <input type="hidden" name="action" value="vive_intake_submit">
                <input type="hidden" name="<?php echo esc_attr(self::NONCE_NAME); ?>" value="<?php echo esc_attr($nonce); ?>">
                <input type="hidden" name="signature_data" id="signature_data">
                <div style="position:absolute;left:-10000px;" aria-hidden="true">
                    <label>Company</label><input type="text" name="company" tabindex="-1" autocomplete="off">
                </div>

                <?php foreach ($sections as $sec): ?>
                    <div class="vive-box">
                        <h3><?php echo esc_html($sec['title']); ?></h3>
                        <div class="vive-grid-1">
                            <?php
                                foreach ($core as $f) if ($f['section'] === $sec['id']) $this->render_field_input($f, null, false);
                                if ($sec['id']==='custom' && !empty($custom_grouped)) {
                                    foreach ($custom_grouped as $grp_title => $fields) {
                                        echo '<h4>'.esc_html($grp_title).'</h4>';
                                        foreach ($fields as $cf) $this->render_custom_input($cf, null, false);
                                    }
                                }
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="vive-help">By submitting, you acknowledge this is an elective wellness service and not intended to diagnose, treat, or cure disease.</div>
                <p><button type="submit" id="vive_submit_btn" style="background:#111;color:#fff;padding:10px 16px;border:none;border-radius:6px;cursor:pointer;">Submit</button></p>
            </form>
        </div>

        <script>
        (function(){
            const canvas=document.getElementById('vive_sign_canvas');
            const clearBtn=document.getElementById('vive_sign_clear');
            const out=document.getElementById('signature_data');
            const btn=document.getElementById('vive_submit_btn');
            if(!canvas) return;
            function sizeCanvas(){ const r=canvas.getBoundingClientRect(),ratio=window.devicePixelRatio||1; canvas.width=Math.floor(r.width*ratio); canvas.height=Math.floor(180*ratio); const ctx=canvas.getContext('2d'); ctx.scale(ratio,ratio); ctx.lineWidth=2; ctx.lineCap='round'; }
            sizeCanvas(); window.addEventListener('resize', sizeCanvas);
            const ctx=canvas.getContext('2d'); let drawing=false,signed=false,last=null;
            function pos(e){ const r=canvas.getBoundingClientRect(),t=e.touches&&e.touches.length?e.touches[0]:e; return {x:t.clientX-r.left,y:t.clientY-r.top}; }
            function start(e){ drawing=true; last=pos(e); e.preventDefault(); }
            function move(e){ if(!drawing) return; const p=pos(e); ctx.beginPath(); ctx.moveTo(last.x,last.y); ctx.lineTo(p.x,p.y); ctx.stroke(); last=p; signed=true; e.preventDefault(); }
            function end(e){ drawing=false; e.preventDefault(); }
            canvas.addEventListener('mousedown',start); canvas.addEventListener('mousemove',move); window.addEventListener('mouseup',end);
            canvas.addEventListener('touchstart',start,{passive:false}); canvas.addEventListener('touchmove',move,{passive:false}); canvas.addEventListener('touchend',end);
            clearBtn&&clearBtn.addEventListener('click',()=>{ ctx.clearRect(0,0,canvas.width,canvas.height); signed=false; out.value=''; });
            btn.closest('form').addEventListener('submit', function(ev){ if(!signed){ alert('Please sign in the signature box.'); ev.preventDefault(); return false; } out.value=canvas.toDataURL('image/png'); });
        })();
        </script>
        <?php
    }

    /** ===== Field renderers ===== */
    private function render_field_input($f, $value = null, $admin = false){
        $key = $f['key']; $label = $f['label']; $req = !empty($f['required']); $type = $f['type'];
        $options = isset($f['options']) ? array_map('trim', explode(',', $f['options'])) : [];
        $name = $key;
        $required_attr = $req ? 'required' : '';

        echo '<div class="vive-row">';
        echo '<label>'.esc_html($label).($req?' *':'').'</label>';

        switch ($type){
            case 'textarea':
                printf('<textarea name="%s" rows="3" %s>%s</textarea>', esc_attr($name), $required_attr, esc_textarea((string)$value));
                break;
            case 'email':
            case 'tel':
            case 'date':
            case 'number':
            case 'text':
                printf('<input type="%s" name="%s" value="%s" %s>', esc_attr($type), esc_attr($name), esc_attr((string)$value), $required_attr);
                break;
            case 'select':
                printf('<select name="%s" %s><option value="">— Select —</option>', esc_attr($name), $required_attr);
                foreach ($options as $o){ $sel=((string)$value===$o)?'selected':''; echo '<option '.$sel.'>'.esc_html($o).'</option>'; }
                echo '</select>';
                break;
            case 'checkbox_one':
                $checked = $value ? 'checked' : '';
                printf('<label class="vive-help" style="font-weight:500;"><input type="checkbox" name="%s" value="1" %s> %s</label>', esc_attr($name), $checked, esc_html($label));
                break;
            case 'checkbox_multi':
                $vals = is_array($value) ? $value : [];
                foreach ($options as $o){ $checked = in_array($o,$vals)?'checked':''; printf('<label class="vive-help" style="font-weight:500;margin-right:10px;"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>', esc_attr($name), esc_attr($o), $checked, esc_html($o)); }
                break;
            case 'medical_flags':
                $med = [
                  'congestive_heart_failure'=>'Congestive heart failure',
                  'kidney_disease_dialysis'=>'Kidney disease or on dialysis',
                  'hypertension_uncontrolled'=>'Uncontrolled hypertension (>160/100)',
                  'diabetes'=>'Diabetes','asthma_copd'=>'Asthma / COPD','seizure_disorder'=>'Seizure disorder',
                  'thyroid_disorder'=>'Thyroid disorder','liver_disease'=>'Liver disease / hepatitis',
                  'clotting_disorder'=>'Blood clotting disorder','cancer_active'=>'Cancer (active)','anemia'=>'Anemia',
                  'g6pd_deficiency'=>'G6PD deficiency','pregnant_breastfeeding'=>'Currently pregnant or breastfeeding',
                  'venous_device'=>'Port / PICC / central line','none'=>'None of the above'
                ];
                $vals = is_array($value) ? $value : [];
                foreach ($med as $mk=>$ml){ $checked = in_array($mk,$vals)?'checked':''; printf('<label class="vive-help" style="font-weight:500;display:block;"><input type="checkbox" name="%s[]" value="%s" %s> %s</label>', esc_attr($name), esc_attr($mk), $checked, esc_html($ml)); }
                break;
            case 'signature':
                echo '<div class="vive-sign-wrap"><canvas class="vive-sign" id="vive_sign_canvas"></canvas><button type="button" id="vive_sign_clear">Clear</button></div>';
                break;
        }
        echo '</div>';
    }

    private function render_custom_input($f, $value = null, $admin = false){
        $key = esc_attr($f['key']); $label = esc_html($f['label']); $type = $f['type']; $req = !empty($f['required']);
        $required_attr = $req ? 'required' : '';
        $opts = isset($f['options']) ? array_map('trim', explode(',', (string)$f['options'])) : [];
        echo '<div class="vive-row"><label>'.$label.($req?' *':'').'</label>';
        $name = 'df['.$key.']';
        switch ($type){
            case 'textarea':
                printf('<textarea name="%s" rows="3" %s>%s</textarea>', esc_attr($name), $required_attr, esc_textarea((string)$value));
                break;
            case 'email':
            case 'tel':
            case 'date':
            case 'number':
            case 'text':
                printf('<input type="%s" name="%s" value="%s" %s>', esc_attr($type), esc_attr($name), esc_attr((string)$value), $required_attr);
                break;
            case 'select':
                printf('<select name="%s" %s><option value="">— Select —</option>', esc_attr($name), $required_attr);
                foreach ($opts as $o){ $sel=((string)$value===$o)?'selected':''; echo '<option '.$sel.'>'.esc_html($o).'</option>'; }
                echo '</select>';
                break;
            case 'radio':
                foreach ($opts as $o){ $checked=((string)$value===$o)?'checked':''; printf('<label class="vive-help" style="font-weight:500;margin-right:10px;"><input type="radio" name="%s" value="%s" %s> %s</label>', esc_attr($name), esc_attr($o), $checked, esc_html($o)); }
                break;
            case 'checkbox':
                $vals = is_array($value)?$value:[];
                $name .= '[]';
                foreach ($opts as $o){ $checked=in_array($o,$vals)?'checked':''; printf('<label class="vive-help" style="font-weight:500;margin-right:10px;"><input type="checkbox" name="%s" value="%s" %s> %s</label>', esc_attr($name), esc_attr($o), $checked, esc_html($o)); }
                break;
        }
        echo '</div>';
    }

    /** ===== Admin: Edit/Sign ===== */
    private function render_admin_edit($id){
        global $wpdb; $table=$this->table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id), ARRAY_A);
        if (!$row){ echo '<p>Record not found.</p>'; return; }

        $sections = $this->get_sections(true);
        $core = $this->get_core(false); // include disabled while editing
        usort($core, function($a,$b){ return [$a['section'],$a['order']] <=> [$b['section'],$b['order']]; });
        $custom_grouped = $this->get_custom_grouped();

        $extra = $row['extra_json'] ? json_decode($row['extra_json'], true) : [];
        if (!is_array($extra)) $extra = [];

        $nonce_edit = wp_create_nonce(self::ADMIN_EDIT_ACTION.'_'.$id);
        $nonce_sign = wp_create_nonce(self::ADMIN_SIGN_ACTION.'_'.$id);

        echo '<h1>Intake #'.esc_html($id).' — View / Edit / Sign</h1>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=vive-intakes')).'" class="button">← Back to list</a></p>';

        echo '<div style="display:grid;grid-template-columns:1.2fr 0.8fr;gap:20px;">';

        echo '<div><h2>Edit Submission</h2>';
        echo '<form method="POST" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="'.esc_attr(self::ADMIN_EDIT_ACTION).'">';
        echo '<input type="hidden" name="id" value="'.esc_attr($id).'">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce_edit).'">';

        foreach ($sections as $sec){
            echo '<h3 style="margin-top:14px;">'.esc_html($sec['title']).'</h3>';
            echo '<div class="vive-grid-1" style="background:#fafafa;border:1px solid #eee;padding:12px;border-radius:8px;">';
            foreach ($core as $f){
                if ($f['section']!==$sec['id']) continue;
                $val = $this->value_from_row($row, $f['key']);
                $this->render_field_input($f, $val, true);
            }
            if ($sec['id']==='custom' && !empty($custom_grouped)){
                foreach ($custom_grouped as $grp=>$fields){
                    echo '<h4>'.esc_html($grp).'</h4>';
                    foreach ($fields as $cf){
                        $val = isset($extra[$cf['key']]) ? $extra[$cf['key']] : null;
                        $this->render_custom_input($cf, $val, true);
                    }
                }
            }
            echo '</div>';
        }

        submit_button('Save Changes');
        echo '</form></div>';

        echo '<div><h2>Provider Signature</h2>';
        echo '<form method="POST" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="'.esc_attr(self::ADMIN_SIGN_ACTION).'">';
        echo '<input type="hidden" name="id" value="'.esc_attr($id).'">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce_sign).'">';
        echo '<input type="hidden" name="provider_signature_data" id="provider_signature_data">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Provider Name</th><td><input name="provider_name" type="text" class="regular-text" value="'.esc_attr($row['provider_name']).'"></td></tr>';
        echo '<tr><th>Signature (draw)</th><td><canvas id="provider_sign_canvas" style="border:1px dashed #aaa;border-radius:6px;background:#fff;width:100%;height:160px;"></canvas><br><button type="button" class="button" id="provider_sign_clear">Clear</button>';
        if ($row['provider_signature_url']) echo '<p>Existing: <a target="_blank" href="'.esc_url($row['provider_signature_url']).'">View</a></p>';
        echo '</td></tr></tbody></table>';
        submit_button($row['provider_signature_url'] ? 'Replace Signature' : 'Sign Record', 'primary');
        if ($row['provider_signed_at']) echo '<p><strong>Signed at:</strong> '.esc_html($row['provider_signed_at']).'</p>';
        echo '</form>';

        echo '<script>(function(){const c=document.getElementById("provider_sign_canvas");const btn=document.getElementById("provider_sign_clear");const out=document.getElementById("provider_signature_data");if(!c)return;function size(){const r=c.getBoundingClientRect(),d=window.devicePixelRatio||1;c.width=Math.floor(r.width*d);c.height=Math.floor(160*d);const x=c.getContext("2d");x.scale(d,d);x.lineWidth=2;x.lineCap="round";}size();window.addEventListener("resize",size);const x=c.getContext("2d");let draw=false,last=null,signed=false;function p(e){const r=c.getBoundingClientRect(),t=e.touches&&e.touches.length?e.touches[0]:e;return {x:t.clientX-r.left,y:t.clientY-r.top};}function start(e){draw=true;last=p(e);e.preventDefault();}function move(e){if(!draw)return;const q=p(e);x.beginPath();x.moveTo(last.x,last.y);x.lineTo(q.x,q.y);x.stroke();last=q;signed=true;e.preventDefault();}function end(e){draw=false;e.preventDefault();}c.addEventListener("mousedown",start);c.addEventListener("mousemove",move);window.addEventListener("mouseup",end);c.addEventListener("touchstart",start,{passive:false});c.addEventListener("touchmove",move,{passive:false});c.addEventListener("touchend",end);btn&&btn.addEventListener("click",()=>{x.clearRect(0,0,c.width,c.height);signed=false;out.value=\"\";});c.closest(\"form\").addEventListener(\"submit\",function(ev){if(!signed){if(!confirm(\"No new signature drawn. Continue?\")){ev.preventDefault();return false;}}out.value=c.toDataURL(\"image/png\");});})();</script>';
        echo '</div>';

        echo '</div>';
    }

    private function value_from_row($row, $key){
        if ($key==='signature_draw') return null;
        if ($key==='medical_flags') return maybe_unserialize($row['medical_flags']);
        if ($key==='addons') return maybe_unserialize($row['addons']);
        if (array_key_exists($key,$row)) return $row[$key];
        return null;
    }

    /** ===== Builder Page ===== */
    public function builder_page(){
        if (!current_user_can($this->CAP)) wp_die('Insufficient permissions.');
        $sections = $this->get_sections(false);
        $core     = $this->get_core(false);
        $custom   = $this->get_custom(false);
        $nonce    = wp_create_nonce(self::ADMIN_BUILDER_SAVE);

        echo '<div class="wrap"><h1>vIVe Form Builder</h1>';
        echo '<form method="POST" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="'.esc_attr(self::ADMIN_BUILDER_SAVE).'">';
        echo '<input type="hidden" name="_wpnonce" value="'.esc_attr($nonce).'">';

        echo '<h2>Sections</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Enabled</th><th>Order</th><th>Section ID</th><th>Title</th></tr></thead><tbody id="secRows">';
        foreach ($sections as $i=>$s){
            echo '<tr>';
            echo '<td><input type="checkbox" name="sections['.$i.'][enabled]" value="1" '.($s['enabled']?'checked':'').'></td>';
            echo '<td><input type="number" name="sections['.$i.'][order]" value="'.esc_attr($s['order']).'" style="width:80px"></td>';
            echo '<td><input type="text" name="sections['.$i.'][id]" value="'.esc_attr($s['id']).'" readonly></td>';
            echo '<td><input type="text" name="sections['.$i.'][title]" value="'.esc_attr($s['title']).'"></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h2 style="margin-top:24px;">Core Fields</h2>';
        echo '<p class="description">Rename labels, move fields, set order, toggle required/enabled. <strong>Locked</strong> items (Full Name, DOB, mandatory consents, typed & drawn signature) cannot be disabled or set non-required.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Enabled</th><th>Required</th><th>Order</th><th>Field Key</th><th>Label</th><th>Type</th><th>Options</th><th>Section</th></tr></thead><tbody>';
        foreach ($core as $i=>$f){
            $disabled_e = !empty($f['lock_enabled']) ? 'disabled' : '';
            $disabled_r = !empty($f['lock_required']) ? 'disabled' : '';
            echo '<tr>';
            echo '<td><input type="checkbox" name="core['.$i.'][enabled]" value="1" '.($f['enabled']?'checked':'').' '.$disabled_e.'></td>';
            echo '<td><input type="checkbox" name="core['.$i.'][required]" value="1" '.($f['required']?'checked':'').' '.$disabled_r.'></td>';
            echo '<td><input type="number" name="core['.$i.'][order]" value="'.esc_attr($f['order']).'" style="width:80px"></td>';
            echo '<td><input type="text" name="core['.$i.'][key]" value="'.esc_attr($f['key']).'" readonly></td>';
            echo '<td><input type="text" name="core['.$i.'][label]" value="'.esc_attr($f['label']).'"></td>';
            echo '<td><input type="text" name="core['.$i.'][type]" value="'.esc_attr($f['type']).'" readonly></td>';
            echo '<td><input type="text" name="core['.$i.'][options]" value="'.esc_attr(isset($f['options'])?$f['options']:'').'" placeholder="Comma separated (for select/checkbox)"></td>';
            echo '<td><select name="core['.$i.'][section]">';
            foreach ($sections as $s) echo '<option value="'.esc_attr($s['id']).'" '.selected($f['section'],$s['id'],false).'>'.esc_html($s['title']).'</option>';
            echo '</select></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<h2 style="margin-top:24px;">Custom Fields</h2>';
        echo '<table class="widefat striped" id="vive-builder-table"><thead><tr><th>Enabled</th><th>Order</th><th>Section Title (groups under “Additional Questions”)</th><th>Key (slug)</th><th>Label</th><th>Type</th><th>Options</th><th>Required</th><th>Remove</th></tr></thead><tbody id="vive-builder-rows">';
        if ($custom) foreach ($custom as $i=>$f) echo $this->builder_row_html($i,$f);
        echo '</tbody></table>';
        echo '<p><button type="button" class="button" id="vive-add-row">+ Add Field</button></p>';
        submit_button('Save Form');

        echo '<template id="vive-row-template">'.$this->builder_row_html('__INDEX__',[
            'enabled'=>1,'order'=>100,'section'=>'Your Group','key'=>'custom_field','label'=>'Your question','type'=>'text','options'=>'','required'=>0
        ],true).'</template>';

        ?>
        <script>
        (function(){
            const addBtn=document.getElementById('vive-add-row');
            const rows=document.getElementById('vive-builder-rows');
            const tpl=document.getElementById('vive-row-template').innerHTML;
            let idx=<?php echo is_array($custom)? count($custom):0; ?>;
            addBtn.addEventListener('click',()=>{ const html=tpl.replace(/__INDEX__/g,idx++); const t=document.createElement('tbody'); t.innerHTML=html; rows.appendChild(t.firstElementChild); });
            rows.addEventListener('click',function(e){ if(e.target.classList.contains('vive-remove')){ e.preventDefault(); const tr=e.target.closest('tr'); tr&&tr.remove(); }});
        })();
        </script>
        <?php
        echo '</form></div>';
    }

    private function builder_row_html($i,$f,$template=false){
        ob_start();
        ?>
        <tr>
            <td><input type="checkbox" name="custom[<?php echo esc_attr($i); ?>][enabled]" value="1" <?php checked(!empty($f['enabled'])); ?>></td>
            <td><input type="number" name="custom[<?php echo esc_attr($i); ?>][order]" value="<?php echo esc_attr($f['order'] ?? 100); ?>" style="width:80px"></td>
            <td><input type="text" name="custom[<?php echo esc_attr($i); ?>][section]" value="<?php echo esc_attr($f['section'] ?? 'Your Group'); ?>"></td>
            <td><input type="text" name="custom[<?php echo esc_attr($i); ?>][key]" value="<?php echo esc_attr($f['key'] ?? 'custom_field'); ?>" pattern="[a-z0-9_\-]+" title="lowercase letters, numbers, dash or underscore"></td>
            <td><input type="text" name="custom[<?php echo esc_attr($i); ?>][label]" value="<?php echo esc_attr($f['label'] ?? 'Your question'); ?>"></td>
            <td>
                <select name="custom[<?php echo esc_attr($i); ?>][type]">
                    <?php foreach (['text','textarea','email','tel','number','date','select','radio','checkbox'] as $t): ?>
                        <option value="<?php echo esc_attr($t); ?>" <?php selected(($f['type'] ?? 'text'),$t); ?>><?php echo esc_html(ucfirst($t)); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" name="custom[<?php echo esc_attr($i); ?>][options]" value="<?php echo esc_attr($f['options'] ?? ''); ?>" placeholder="For select/radio/checkbox"></td>
            <td><input type="checkbox" name="custom[<?php echo esc_attr($i); ?>][required]" value="1" <?php checked(!empty($f['required'])); ?>></td>
            <td><a href="#" class="button link-delete vive-remove">Remove</a></td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /** ===== Helpers to get configs ===== */
    private function get_sections($only_enabled){
        $s = get_option(self::OPTION_SECTIONS, []);
        $s = is_array($s)? $s : [];
        if ($only_enabled) $s = array_values(array_filter($s, fn($x)=>!empty($x['enabled'])));
        usort($s, fn($a,$b)=> intval($a['order']) <=> intval($b['order']));
        return $s;
    }
    private function get_core($only_enabled){
        $c = get_option(self::OPTION_CORE, []);
        $c = is_array($c)? $c : [];
        if ($only_enabled) $c = array_values(array_filter($c, fn($x)=>!empty($x['enabled'])));
        usort($c, fn($a,$b)=> [$a['section'],$a['order']] <=> [$b['section'],$b['order']]);
        return $c;
    }
    private function get_custom($only_enabled){
        $f = get_option(self::OPTION_CUSTOM, []);
        $f = is_array($f)? $f : [];
        if ($only_enabled) $f = array_values(array_filter($f, fn($x)=>!empty($x['enabled'])));
        usort($f, fn($a,$b)=> [$a['section'],$a['order']] <=> [$b['section'],$b['order']]);
        return $f;
    }
    private function get_custom_grouped(){
        $f = $this->get_custom(true);
        if (!$f) return [];
        $g = [];
        foreach ($f as $x){ $sec = $x['section'] ?: 'Additional Questions'; if(!isset($g[$sec])) $g[$sec]=[]; $g[$sec][]=$x; }
        foreach ($g as &$arr){ usort($arr, fn($a,$b)=> intval($a['order']) <=> intval($b['order'])); }
        return $g;
    }

    /** ===== Save Builder ===== */
    public function handle_builder_save(){
        if (!current_user_can($this->CAP)) wp_die('Insufficient permissions.');
        check_admin_referer(self::ADMIN_BUILDER_SAVE);

        // Sections
        $sections = isset($_POST['sections']) && is_array($_POST['sections']) ? $_POST['sections'] : [];
        $sec_clean = [];
        foreach ($sections as $s){
            $sec_clean[] = [
                'id'      => sanitize_key($s['id']),
                'title'   => sanitize_text_field($s['title'] ?? ''),
                'enabled' => !empty($s['enabled']) ? 1 : 0,
                'order'   => isset($s['order']) ? intval($s['order']) : 100
            ];
        }
        usort($sec_clean, fn($a,$b)=> intval($a['order']) <=> intval($b['order']));
        update_option(self::OPTION_SECTIONS, $sec_clean);

        // Core
        $core = get_option(self::OPTION_CORE, []);
        $posted_core = isset($_POST['core']) && is_array($_POST['core']) ? $_POST['core'] : [];
        $core_clean = [];
        foreach ($posted_core as $idx=>$f){
            $orig = $core[$idx] ?? ['key'=>$f['key'],'type'=>($f['type'] ?? 'text')];
            $lock_en = !empty($orig['lock_enabled']); $lock_req = !empty($orig['lock_required']);
            $enabled = $lock_en ? 1 : (!empty($f['enabled']) ? 1 : 0);
            $required= $lock_req ? 1 : (!empty($f['required']) ? 1 : 0);

            $core_clean[] = [
                'key' => sanitize_key($orig['key']),
                'label' => sanitize_text_field($f['label'] ?? $orig['key']),
                'section' => sanitize_key($f['section'] ?? 'patient'),
                'type' => sanitize_text_field($orig['type']),
                'options' => sanitize_text_field($f['options'] ?? ($orig['options'] ?? '')),
                'required' => $required,
                'enabled' => $enabled,
                'order' => isset($f['order']) ? intval($f['order']) : 100,
                'lock_enabled' => $lock_en ? 1 : 0,
                'lock_required'=> $lock_req ? 1 : 0,
            ];
        }
        usort($core_clean, fn($a,$b)=> [$a['section'],$a['order']] <=> [$b['section'],$b['order']]);
        update_option(self::OPTION_CORE, $core_clean);

        // Custom
        $custom = isset($_POST['custom']) && is_array($_POST['custom']) ? $_POST['custom'] : [];
        $cust_clean = [];
        foreach ($custom as $f){
            $cust_clean[] = [
                'enabled'=> !empty($f['enabled']) ? 1 : 0,
                'order'  => isset($f['order']) ? intval($f['order']) : 100,
                'section'=> sanitize_text_field($f['section'] ?? 'Your Group'),
                'key'    => sanitize_title_with_dashes($f['key'] ?? 'custom_field'),
                'label'  => sanitize_text_field($f['label'] ?? 'Your question'),
                'type'   => in_array(($f['type'] ?? 'text'),['text','textarea','email','tel','number','date','select','radio','checkbox']) ? $f['type'] : 'text',
                'options'=> sanitize_text_field($f['options'] ?? ''),
                'required'=> !empty($f['required']) ? 1 : 0,
            ];
        }
        usort($cust_clean, fn($a,$b)=> [$a['section'],$a['order']] <=> [$b['section'],$b['order']]);
        update_option(self::OPTION_CUSTOM, $cust_clean);

        wp_safe_redirect(admin_url('admin.php?page=vive-intakes-builder&saved=1'));
        exit;
    }

    /** ===== Submission handler ===== */
    private function field($name){ return isset($_POST[$name]) ? wp_unslash($_POST[$name]) : ''; }
    private function field_bool($name){ return isset($_POST[$name]) && $_POST[$name] ? 1 : 0; }

    public function handle_submit(){
        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce($_POST[self::NONCE_NAME], self::NONCE_ACTION)) wp_die('Invalid submission.');
        if (!empty($_POST['company'])) { wp_safe_redirect(add_query_arg('submitted','1',wp_get_referer())); exit; }

        $core = $this->get_core(true);
        $required_errors = [];
        $signature_data = null;
        $signature_name = null;

        foreach ($core as $f){
            $key=$f['key']; if (!$f['enabled']) continue;
            if ($f['type']==='signature') { $signature_data = $this->field('signature_data'); continue; }
            $val = $this->field($key);
            if ($f['type']==='checkbox_one') $val = isset($_POST[$key]) ? 1 : 0;
            if (!empty($f['required'])) {
                $empty = ($f['type']==='checkbox_one') ? ($val?false:true) : (trim((string)$val)==='');
                if ($empty) $required_errors[] = sprintf('Field "%s" is required.', $f['label']);
            }
            if ($key==='signature_name') $signature_name = sanitize_text_field($val);
        }
        $lock_keys = ['full_name','dob','consent_treatment','consent_financial','consent_privacy','signature_name','signature_draw'];
        foreach ($lock_keys as $lk){
            $f = $this->find_core($core,$lk);
            if (!$f || empty($f['enabled']) || empty($f['required'])) $required_errors[] = 'A mandatory consent or identity field was disabled. Please contact the clinic.';
        }
        if (!$signature_data) $required_errors[] = 'Signature pad is required.';
        if ($required_errors){
            $q = http_build_query(['errors'=>implode('|',$required_errors)]);
            wp_safe_redirect(add_query_arg($q, wp_get_referer())); exit;
        }

        // Gather core values
        $vals = [];
        foreach ($core as $f){
            $k=$f['key']; if ($f['type']==='signature') continue;
            if ($f['type']==='checkbox_one') $vals[$k] = isset($_POST[$k]) ? 1 : 0;
            elseif ($f['type']==='checkbox_multi') $vals[$k] = isset($_POST[$k]) ? array_map('sanitize_text_field', (array)$_POST[$k]) : [];
            elseif ($f['key']==='medical_flags') $vals[$k] = isset($_POST[$k]) ? array_map('sanitize_text_field',(array)$_POST[$k]) : [];
            else $vals[$k] = sanitize_text_field($this->field($k));
        }

        // Signature PNG
        $signature_url = null;
        if (strpos($signature_data,'data:image/png;base64,')===0){
            $png = base64_decode(str_replace('data:image/png;base64,','',$signature_data));
            if ($png!==false){
                $filename='vive-signature-'.time().'-'.wp_generate_uuid4().'.png';
                $upload=wp_upload_bits($filename,null,$png);
                if (empty($upload['error'])) $signature_url=$upload['url'];
            }
        }

        // Custom answers
        $custom_defs = $this->get_custom(true);
        $df_input = isset($_POST['df']) && is_array($_POST['df']) ? $_POST['df'] : [];
        $extra = [];
        foreach ($custom_defs as $f){
            $k=$f['key']; $t=$f['type'];
            if (!isset($df_input[$k])) { $extra[$k]=null; continue; }
            $v = $df_input[$k];
            if ($t==='checkbox')      $extra[$k]=is_array($v)? array_map('sanitize_text_field',$v):[];
            elseif ($t==='number')    $extra[$k]=is_array($v)? null: floatval($v);
            else                      $extra[$k]=is_array($v)? null: sanitize_text_field($v);
            if (!empty($f['required'])) {
                $is_empty = is_array($extra[$k]) ? (count(array_filter($extra[$k]))===0) : (trim((string)$extra[$k])==='');
                if ($is_empty) $required_errors[] = sprintf('Field "%s" is required.', $f['label']);
            }
        }
        if ($required_errors){
            $q = http_build_query(['errors'=>implode('|',$required_errors)]);
            wp_safe_redirect(add_query_arg($q, wp_get_referer())); exit;
        }

        // Insert row
        global $wpdb; $table=$this->table_name();
        $data = [
            'created_at'=>current_time('mysql'),
            'ip_address'=>isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : null,
            'user_agent'=>isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']),0,255) : null,

            'full_name'=>$vals['full_name'] ?? '',
            'dob'=>$vals['dob'] ?? '',
            'sex_at_birth'=>$vals['sex_at_birth'] ?? null,
            'pronouns'=>$vals['pronouns'] ?? null,
            'phone'=>$vals['phone'] ?? null,
            'sms_opt_in'=>!empty($vals['sms_opt_in'])?1:0,
            'email'=>isset($vals['email'])?sanitize_email($vals['email']):null,
            'email_hipaa_opt_in'=>!empty($vals['email_hipaa_opt_in'])?1:0,
            'address1'=>$vals['address1'] ?? null,
            'city'=>$vals['city'] ?? null,
            'state'=>$vals['state'] ?? null,
            'zip'=>$vals['zip'] ?? null,
            'emergency_name'=>$vals['emergency_name'] ?? null,
            'emergency_relationship'=>$vals['emergency_relationship'] ?? null,
            'emergency_phone'=>$vals['emergency_phone'] ?? null,
            'medical_flags'=>isset($vals['medical_flags'])? maybe_serialize($vals['medical_flags']) : null,
            'allergies'=>$vals['allergies'] ?? null,
            'meds_rx'=>$vals['meds_rx'] ?? null,
            'meds_otc'=>$vals['meds_otc'] ?? null,
            'on_anticoagulants'=>$vals['on_anticoagulants'] ?? null,
            'on_diuretics'=>$vals['on_diuretics'] ?? null,
            'status_nvd'=>$vals['status_nvd'] ?? null,
            'status_alcohol'=>$vals['status_alcohol'] ?? null,
            'last_oral_intake'=>$vals['last_oral_intake'] ?? null,
            'prior_iv_nad'=>$vals['prior_iv_nad'] ?? null,
            'access_preference'=>$vals['access_preference'] ?? null,
            'hard_stick'=>$vals['hard_stick'] ?? null,
            'allow_numbing'=>$vals['allow_numbing'] ?? null,
            'vital_bp'=>$vals['vital_bp'] ?? null,
            'vital_hr'=>isset($vals['vital_hr']) && $vals['vital_hr']!=='' ? intval($vals['vital_hr']) : null,
            'vital_temp_f'=>isset($vals['vital_temp_f']) && $vals['vital_temp_f']!=='' ? floatval($vals['vital_temp_f']) : null,
            'vital_o2'=>isset($vals['vital_o2']) && $vals['vital_o2']!=='' ? intval($vals['vital_o2']) : null,
            'therapy_selection'=>$vals['therapy_selection'] ?? null,
            'therapy_other'=>$vals['therapy_other'] ?? null,
            'nad_dose'=>$vals['nad_dose'] ?? null,
            'addons'=>isset($vals['addons'])? maybe_serialize($vals['addons']) : null,
            'addons_other'=>$vals['addons_other'] ?? null,
            'consent_treatment'=>!empty($vals['consent_treatment'])?1:0,
            'consent_financial'=>!empty($vals['consent_financial'])?1:0,
            'consent_comms_email'=>!empty($vals['consent_comms_email'])?1:0,
            'consent_comms_sms'=>!empty($vals['consent_comms_sms'])?1:0,
            'consent_privacy'=>!empty($vals['consent_privacy'])?1:0,
            'consent_photo'=>!empty($vals['consent_photo'])?1:0,
            'signature_name'=>sanitize_text_field($vals['signature_name'] ?? ''),
            'signature_url'=>$signature_url,
            'signed_at'=>current_time('mysql'),
            'extra_json'=>wp_json_encode($extra),
            'honeypot'=>sanitize_text_field($this->field('company')),
        ];
        $wpdb->insert($table,$data);
        wp_safe_redirect(add_query_arg(['submitted'=>1,'ref'=>$wpdb->insert_id], wp_get_referer())); exit;
    }

    private function find_core($core,$key){ foreach ($core as $f) if ($f['key']===$key) return $f; return null; }

    /** ===== Admin: Save edits ===== */
    public function handle_admin_edit(){
        if (!current_user_can($this->CAP)) wp_die('Insufficient permissions.');
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        if (!$id || !wp_verify_nonce($_POST['_wpnonce'], self::ADMIN_EDIT_ACTION.'_'.$id)) wp_die('Invalid request.');

        global $wpdb; $table=$this->table_name();
        $row = $wpdb->get_row($wpdb->prepare("SELECT extra_json FROM $table WHERE id=%d",$id));
        $extra = $row && $row->extra_json ? json_decode($row->extra_json,true) : [];
        if (!is_array($extra)) $extra=[];

        $core = $this->get_core(false);
        $update = [];
        foreach ($core as $f){
            $k=$f['key']; $t=$f['type'];
            if ($t==='signature') continue;
            if ($t==='checkbox_one') $val = isset($_POST[$k]) ? 1 : 0;
            elseif ($t==='checkbox_multi') $val = isset($_POST[$k]) ? array_map('sanitize_text_field',(array)$_POST[$k]) : [];
            elseif ($k==='medical_flags') $val = isset($_POST[$k]) ? array_map('sanitize_text_field',(array)$_POST[$k]) : [];
            else $val = sanitize_text_field($_POST[$k] ?? '');

            $this->assign_core_value($update, $k, $val);
        }

        $defs = $this->get_custom(false);
        $df = isset($_POST['df']) && is_array($_POST['df']) ? $_POST['df'] : [];
        foreach ($defs as $f){
            $k=$f['key']; $t=$f['type'];
            if (!isset($df[$k])) { $extra[$k]=null; continue; }
            $v=$df[$k];
            if ($t==='checkbox') $extra[$k]=is_array($v)?array_map('sanitize_text_field',$v):[];
            elseif ($t==='number') $extra[$k]=is_array($v)?null:floatval($v);
            else $extra[$k]=is_array($v)?null:sanitize_text_field($v);
        }
        $update['extra_json']=wp_json_encode($extra);

        $wpdb->update($table,$update,['id'=>$id]);
        wp_safe_redirect(admin_url('admin.php?page=vive-intakes&action=edit&id='.$id.'&updated=1')); exit;
    }

    private function assign_core_value(&$arr,$k,$val){
        $columns = [
            'full_name','dob','sex_at_birth','pronouns','phone','sms_opt_in','email','email_hipaa_opt_in','address1','city','state','zip',
            'emergency_name','emergency_relationship','emergency_phone','medical_flags','allergies','meds_rx','meds_otc','on_anticoagulants','on_diuretics','status_nvd',
            'status_alcohol','last_oral_intake','prior_iv_nad','access_preference','hard_stick','allow_numbing','vital_bp','vital_hr','vital_temp_f','vital_o2',
            'therapy_selection','therapy_other','nad_dose','addons','addons_other','consent_treatment','consent_financial','consent_comms_email','consent_comms_sms','consent_privacy','consent_photo','signature_name'
        ];
        if (!in_array($k,$columns,true)) return;
        if ($k==='medical_flags' || $k==='addons') $arr[$k]=maybe_serialize($val);
        else $arr[$k]=$val;
    }

    /** ===== Tools (Export / Import / Restore) ===== */
    public function tools_page(){
        if (!current_user_can($this->CAP)) wp_die('Insufficient permissions.');
        $nonce_export  = wp_create_nonce(self::ADMIN_TOOLS_EXPORT);
        $nonce_import  = wp_create_nonce(self::ADMIN_TOOLS_IMPORT);
        $nonce_restore = wp_create_nonce(self::ADMIN_TOOLS_RESTORE);
        $backups = get_option(self::OPTION_BACKUPS, []);
        ?>
        <div class="wrap">
          <h1>vIVe Intake — Tools</h1>
          <p>Export your Form Builder config, import it into another site/version, or restore a previous backup.</p>

          <h2>Export configuration</h2>
          <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ADMIN_TOOLS_EXPORT); ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_export); ?>">
            <?php submit_button('Download JSON', 'primary', 'submit', false); ?>
          </form>

          <h2 style="margin-top:24px;">Import configuration</h2>
          <form method="POST" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ADMIN_TOOLS_IMPORT); ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_import); ?>">
            <input type="file" name="vive_config_json" accept=".json" required>
            <?php submit_button('Import JSON & Backup Current', 'secondary', 'submit', false); ?>
          </form>

          <h2 style="margin-top:24px;">Backups</h2>
          <?php if (empty($backups)): ?>
            <p>No backups yet.</p>
          <?php else: ?>
            <table class="widefat striped"><thead><tr><th>Date</th><th>Size</th><th>Action</th></tr></thead><tbody>
              <?php foreach ($backups as $stamp => $bundle): ?>
                <tr>
                  <td><?php echo esc_html(date_i18n(get_option('date_format').' '.get_option('time_format'), intval($stamp))); ?></td>
                  <td><?php echo esc_html(strlen(wp_json_encode($bundle))); ?> bytes</td>
                  <td>
                    <form method="POST" style="display:inline" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                      <input type="hidden" name="action" value="<?php echo esc_attr(self::ADMIN_TOOLS_RESTORE); ?>">
                      <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_restore); ?>">
                      <input type="hidden" name="stamp" value="<?php echo esc_attr($stamp); ?>">
                      <?php submit_button('Restore', 'small', 'submit', false, ['onclick'=>'return confirm("Restore this backup? Your current config will be overwritten (and backed up separately).");']); ?>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody></table>
          <?php endif; ?>
        </div>
        <?php
    }

    private function get_config_bundle(){
        return [
            'meta' => ['plugin' => 'vive-intake', 'version' => get_option(self::OPTION_VERSION, 'unknown'), 'exported_at' => time()],
            'sections' => get_option(self::OPTION_SECTIONS, []),
            'core'     => get_option(self::OPTION_CORE, []),
            'custom'   => get_option(self::OPTION_CUSTOM, []),
        ];
    }

    private function apply_config_bundle($bundle){
        if (!is_array($bundle) || empty($bundle['sections']) || !isset($bundle['core']) || !isset($bundle['custom'])) return new WP_Error('bad_bundle','Invalid bundle');
        update_option(self::OPTION_SECTIONS, is_array($bundle['sections']) ? $bundle['sections'] : []);
        update_option(self::OPTION_CORE,     is_array($bundle['core'])     ? $bundle['core']     : []);
        update_option(self::OPTION_CUSTOM,   is_array($bundle['custom'])   ? $bundle['custom']   : []);
        return true;
    }

    private function backup_config(){
        $backups = get_option(self::OPTION_BACKUPS, []);
        if (!is_array($backups)) $backups = [];
        $stamp = time();
        $backups[$stamp] = $this->get_config_bundle();
        if (count($backups) > 10) { ksort($backups); while (count($backups) > 10) array_shift($backups); }
        update_option(self::OPTION_BACKUPS, $backups);
        return $stamp;
    }

    public function handle_tools_export(){
        if (!current_user_can($this->CAP)) wp_die('Insufficient permissions.');
        check_admin_referer(self::ADMIN_TOOLS_EXPORT);
        $json = wp_json_encode($this->get_config_bundle(), JSON_PRETTY_PRINT);
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="vive-intake-config-'.date('Ymd-His').'.json"');
        echo $json; exit;
    }

    public function handle_tools_import(){
        if (!current_user_can($this->CAP)) wp_die('Insufficient permissions.');
        check_admin_referer(self::ADMIN_TOOLS_IMPORT);
        if (empty($_FILES['vive_config_json']['tmp_name'])) wp_die('No file uploaded.');
        $raw = file_get_contents($_FILES['vive_config_json']['tmp_name']);
        $bundle = json_decode($raw, true);
        if (!$bundle) wp_die('Invalid JSON.');
        $this->backup_config();
        $ok = $this->apply_config_bundle($bundle);
        if (is_wp_error($ok)) wp_die($ok->get_error_message());
        wp_safe_redirect(admin_url('admin.php?page=vive-intakes-tools&imported=1')); exit;
    }

    public function handle_tools_restore(){
        if (!current_user_can($this->CAP)) wp_die('Insufficient permissions.');
        check_admin_referer(self::ADMIN_TOOLS_RESTORE);
        $stamp = isset($_POST['stamp']) ? sanitize_text_field($_POST['stamp']) : '';
        $backups = get_option(self::OPTION_BACKUPS, []);
        if (empty($backups[$stamp])) wp_die('Backup not found.');
        $this->backup_config();
        $ok = $this->apply_config_bundle($backups[$stamp]);
        if (is_wp_error($ok)) wp_die($ok->get_error_message());
        wp_safe_redirect(admin_url('admin.php?page=vive-intakes-tools&restored=1')); exit;
    }
}

new Vive_Intake_Plugin();

/** ========= OPTIONAL: GitHub auto-updates (Plugin Update Checker) =========
 * Repo: https://github.com/kublid/viveformplugin
 * Steps:
 *   1) Download PUC: https://github.com/YahnisElsts/plugin-update-checker
 *   2) Put the folder "plugin-update-checker" inside this plugin directory (/wp-content/plugins/vive-intake/)
 *   3) Keep your GitHub repository public (or provide token per PUC docs) and publish releases with a ZIP asset, or use tags.
 */
if (is_admin()) {
    // If PUC is present, wire it up.
    if (file_exists(__DIR__ . '/plugin-update-checker/plugin-update-checker.php')) {
        require __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
        $viveUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
            'https://github.com/kublid/viveformplugin', // GitHub repo URL
            __FILE__,                                   // path to main plugin file
            'vive-intake'                               // plugin slug
        );
        // If you attach ZIPs to GitHub Releases:
        if (method_exists($viveUpdateChecker, 'getVcsApi')) {
            $api = $viveUpdateChecker->getVcsApi();
            if ($api && method_exists($api, 'enableReleaseAssets')) {
                $api->enableReleaseAssets();
            }
        }
        // Optional: set a branch if you don't use releases (default: master/main detection)
        // $viveUpdateChecker->setBranch('main');
    }
}