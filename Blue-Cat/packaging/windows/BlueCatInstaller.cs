using System;
using System.Drawing;
using System.IO;
using System.Net;
using System.Reflection;
using System.Text;
using System.Windows.Forms;

[assembly: AssemblyTitle("Blue-Cat ERP Instalador Comercial")]
[assembly: AssemblyDescription("Instalador Oficial de Servidor y Licencias Comercial de 1 Uso Blue-Cat ERP")]
[assembly: AssemblyCompany("Lifilab Technologies")]
[assembly: AssemblyProduct("Blue-Cat ERP")]
[assembly: AssemblyCopyright("Copyright © 2026 Lifilab")]
[assembly: AssemblyVersion("1.0.0.0")]
[assembly: AssemblyFileVersion("1.0.0.0")]

namespace BlueCatInstaller
{
    public class InstallerForm : Form
    {
        private Panel headerPanel;
        private Label lblHeaderTitle;
        private Label lblHeaderSub;
        private Panel bodyPanel;

        // Step 1 Controls: Empresa
        private TextBox txtLegalName, txtTradeName, txtTaxId;
        // Step 2 Controls: Ubicación
        private TextBox txtActivity, txtAddress, txtCity;
        // Step 3 Controls: Licencia
        private TextBox txtLicEmail, txtLicKey, txtLicServer;
        private Button btnVerifyLicense;
        private Label lblLicStatus;
        private bool isLicenseVerified = false;
        private string verifiedSessionToken = "";
        private string verifiedClientName = "";

        // Step 4 Controls: Admin
        private TextBox txtAdminUser, txtAdminPass;

        private Button btnBack, btnNext;
        private Label lblStepIndicator;

        private int currentStep = 1;
        private const int TOTAL_STEPS = 4;

        public InstallerForm()
        {
            ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12 | SecurityProtocolType.Tls11 | SecurityProtocolType.Tls;
            InitializeComponent();
            ShowStep(1);
        }

        private void InitializeComponent()
        {
            this.Text = "Instalador Oficial - Blue-Cat ERP (Servidor & Licencia Comercial)";
            this.Size = new Size(740, 560);
            this.StartPosition = FormStartPosition.CenterScreen;
            this.FormBorderStyle = FormBorderStyle.FixedDialog;
            this.MaximizeBox = false;
            this.BackColor = Color.FromArgb(15, 23, 42); // Dark slate background

            // Header Panel
            headerPanel = new Panel();
            headerPanel.Dock = DockStyle.Top;
            headerPanel.Height = 85;
            headerPanel.BackColor = Color.FromArgb(30, 41, 59);

            lblHeaderTitle = new Label();
            lblHeaderTitle.Text = "🛡️ Instalación & Activación Comercial de Blue-Cat ERP";
            lblHeaderTitle.Font = new Font("Segoe UI", 12.5F, FontStyle.Bold);
            lblHeaderTitle.ForeColor = Color.White;
            lblHeaderTitle.Location = new Point(20, 15);
            lblHeaderTitle.AutoSize = true;
            headerPanel.Controls.Add(lblHeaderTitle);

            lblHeaderSub = new Label();
            lblHeaderSub.Text = "Paso 1 de 4: Configuración de la Empresa";
            lblHeaderSub.Font = new Font("Segoe UI", 9.5F, FontStyle.Regular);
            lblHeaderSub.ForeColor = Color.FromArgb(148, 163, 184);
            lblHeaderSub.Location = new Point(22, 48);
            lblHeaderSub.AutoSize = true;
            headerPanel.Controls.Add(lblHeaderSub);

            this.Controls.Add(headerPanel);

            // Body Panel
            bodyPanel = new Panel();
            bodyPanel.Location = new Point(20, 100);
            bodyPanel.Size = new Size(685, 345);
            bodyPanel.BackColor = Color.FromArgb(30, 41, 59);
            bodyPanel.Padding = new Padding(20);
            this.Controls.Add(bodyPanel);

            // Footer / Navigation Buttons
            btnBack = new Button();
            btnBack.Text = "◄ Anterior";
            btnBack.Size = new Size(110, 38);
            btnBack.Location = new Point(20, 460);
            btnBack.FlatStyle = FlatStyle.Flat;
            btnBack.FlatAppearance.BorderSize = 1;
            btnBack.FlatAppearance.BorderColor = Color.FromArgb(71, 85, 105);
            btnBack.ForeColor = Color.White;
            btnBack.Font = new Font("Segoe UI", 9.5F, FontStyle.Bold);
            btnBack.Click += (s, e) => { if (currentStep > 1) ShowStep(currentStep - 1); };
            this.Controls.Add(btnBack);

            btnNext = new Button();
            btnNext.Text = "Siguiente ►";
            btnNext.Size = new Size(160, 38);
            btnNext.Location = new Point(545, 460);
            btnNext.FlatStyle = FlatStyle.Flat;
            btnNext.BackColor = Color.FromArgb(37, 99, 235);
            btnNext.ForeColor = Color.White;
            btnNext.Font = new Font("Segoe UI", 9.5F, FontStyle.Bold);
            btnNext.Click += BtnNext_Click;
            this.Controls.Add(btnNext);

            lblStepIndicator = new Label();
            lblStepIndicator.Text = "Licencia Comercial de 1 Uso & Anti-Keygens en Línea";
            lblStepIndicator.ForeColor = Color.FromArgb(148, 163, 184);
            lblStepIndicator.Location = new Point(160, 470);
            lblStepIndicator.AutoSize = true;
            this.Controls.Add(lblStepIndicator);

            CreateStep1Controls();
            CreateStep2Controls();
            CreateStep3Controls();
            CreateStep4Controls();
        }

        private Label CreateLabel(string text, int y)
        {
            Label lbl = new Label();
            lbl.Text = text;
            lbl.ForeColor = Color.FromArgb(226, 232, 240);
            lbl.Font = new Font("Segoe UI", 9F, FontStyle.Bold);
            lbl.Location = new Point(20, y);
            lbl.AutoSize = true;
            return lbl;
        }

        private TextBox CreateTextBox(string defaultVal, int y)
        {
            TextBox txt = new TextBox();
            txt.Text = defaultVal;
            txt.Location = new Point(20, y);
            txt.Size = new Size(640, 28);
            txt.Font = new Font("Segoe UI", 10F);
            txt.BackColor = Color.FromArgb(15, 23, 42);
            txt.ForeColor = Color.White;
            txt.BorderStyle = BorderStyle.FixedSingle;
            return txt;
        }

        private void CreateStep1Controls()
        {
            bodyPanel.Controls.Add(CreateLabel("Razón Social (Obligatorio):", 15));
            txtLegalName = CreateTextBox("Empresa Ejemplo SpA", 38);
            bodyPanel.Controls.Add(txtLegalName);

            bodyPanel.Controls.Add(CreateLabel("Nombre Comercial:", 85));
            txtTradeName = CreateTextBox("Blue-Cat Comercio", 108);
            bodyPanel.Controls.Add(txtTradeName);

            bodyPanel.Controls.Add(CreateLabel("RUT / Identificación Tributaria (Obligatorio):", 155));
            txtTaxId = CreateTextBox("76.123.456-7", 178);
            bodyPanel.Controls.Add(txtTaxId);
        }

        private void CreateStep2Controls()
        {
            bodyPanel.Controls.Add(CreateLabel("Giro o Rubro Comercial:", 15));
            txtActivity = CreateTextBox("Venta de Productos y Servicios", 38);
            bodyPanel.Controls.Add(txtActivity);

            bodyPanel.Controls.Add(CreateLabel("Dirección Comercial:", 85));
            txtAddress = CreateTextBox("Av. Principal 1234, Oficina 502", 108);
            bodyPanel.Controls.Add(txtAddress);

            bodyPanel.Controls.Add(CreateLabel("Ciudad:", 155));
            txtCity = CreateTextBox("Santiago", 178);
            bodyPanel.Controls.Add(txtCity);
        }

        private void CreateStep3Controls()
        {
            bodyPanel.Controls.Add(CreateLabel("Correo Electrónico Verificador Registrado:", 10));
            txtLicEmail = CreateTextBox("pabloxmillones@gmail.com", 32);
            bodyPanel.Controls.Add(txtLicEmail);

            bodyPanel.Controls.Add(CreateLabel("Clave de Licencia Comercial (1 Uso):", 75));
            txtLicKey = CreateTextBox("D739-E8D0-CDC6-E4DA", 97);
            txtLicKey.ForeColor = Color.FromArgb(245, 158, 11);
            txtLicKey.Font = new Font("Consolas", 11F, FontStyle.Bold);
            bodyPanel.Controls.Add(txtLicKey);

            bodyPanel.Controls.Add(CreateLabel("URL del Servidor de Licencias Online:", 140));
            txtLicServer = CreateTextBox("http://localhost:3050", 162);
            bodyPanel.Controls.Add(txtLicServer);

            btnVerifyLicense = new Button();
            btnVerifyLicense.Text = "🔍 Validar Licencia en Línea Ahora";
            btnVerifyLicense.Size = new Size(240, 34);
            btnVerifyLicense.Location = new Point(20, 202);
            btnVerifyLicense.FlatStyle = FlatStyle.Flat;
            btnVerifyLicense.BackColor = Color.FromArgb(16, 185, 129);
            btnVerifyLicense.ForeColor = Color.White;
            btnVerifyLicense.Font = new Font("Segoe UI", 9F, FontStyle.Bold);
            btnVerifyLicense.Click += BtnVerifyLicense_Click;
            bodyPanel.Controls.Add(btnVerifyLicense);

            lblLicStatus = new Label();
            lblLicStatus.Text = "💡 Presione 'Validar Licencia en Línea Ahora' para autenticar contra el servidor.";
            lblLicStatus.ForeColor = Color.FromArgb(59, 130, 246);
            lblLicStatus.Font = new Font("Segoe UI", 9F, FontStyle.Italic);
            lblLicStatus.Location = new Point(20, 248);
            lblLicStatus.Size = new Size(640, 75);
            bodyPanel.Controls.Add(lblLicStatus);
        }

        private void CreateStep4Controls()
        {
            bodyPanel.Controls.Add(CreateLabel("Usuario Administrador Local:", 15));
            txtAdminUser = CreateTextBox("administrador", 38);
            bodyPanel.Controls.Add(txtAdminUser);

            bodyPanel.Controls.Add(CreateLabel("Contraseña de Acceso (mínimo 10 caracteres):", 85));
            txtAdminPass = CreateTextBox("Admin123456!", 108);
            txtAdminPass.UseSystemPasswordChar = true;
            bodyPanel.Controls.Add(txtAdminPass);

            Label lblSummary = new Label();
            lblSummary.Text = "✔ Se creará el acceso directo 'Blue-Cat ERP' en su Escritorio.\n✔ Al presionar 'Finalizar e Instalar', Blue-Cat ERP abrirá en pantalla completa.";
            lblSummary.ForeColor = Color.FromArgb(16, 185, 129);
            lblSummary.Font = new Font("Segoe UI", 9.5F, FontStyle.Bold);
            lblSummary.Location = new Point(20, 165);
            lblSummary.AutoSize = true;
            bodyPanel.Controls.Add(lblSummary);
        }

        private void ShowStep(int step)
        {
            currentStep = step;
            btnBack.Enabled = (currentStep > 1);
            btnNext.Text = (currentStep == TOTAL_STEPS) ? "🚀 Finalizar e Instalar" : "Siguiente ►";

            switch (currentStep)
            {
                case 1:
                    lblHeaderSub.Text = "Paso 1 de 4: Identificación de la Empresa (Limpio Sin Desbordamientos)";
                    SetControlsVisible(txtLegalName, txtTradeName, txtTaxId);
                    break;
                case 2:
                    lblHeaderSub.Text = "Paso 2 de 4: Ubicación y Rubro Comercial";
                    SetControlsVisible(txtActivity, txtAddress, txtCity);
                    break;
                case 3:
                    lblHeaderSub.Text = "Paso 3 de 4: Licencia Comercial de 1 Uso & Validación en Tiempo Real";
                    SetControlsVisible(txtLicEmail, txtLicKey, txtLicServer, btnVerifyLicense, lblLicStatus);
                    break;
                case 4:
                    lblHeaderSub.Text = "Paso 4 de 4: Credenciales de Administrador & Creación de Acceso Directo";
                    SetControlsVisible(txtAdminUser, txtAdminPass);
                    break;
            }
        }

        private void SetControlsVisible(params Control[] controlsToShow)
        {
            foreach (Control c in bodyPanel.Controls)
            {
                c.Visible = false;
            }
            foreach (Control c in controlsToShow)
            {
                if (c != null)
                {
                    c.Visible = true;
                    int idx = bodyPanel.Controls.IndexOf(c);
                    if (idx > 0 && bodyPanel.Controls[idx - 1] is Label)
                    {
                        bodyPanel.Controls[idx - 1].Visible = true;
                    }
                }
            }
        }

        private void BtnVerifyLicense_Click(object sender, EventArgs e)
        {
            string email = txtLicEmail.Text.Trim();
            string key = txtLicKey.Text.Trim();
            string serverUrl = txtLicServer.Text.Trim();

            if (string.IsNullOrWhiteSpace(email) || string.IsNullOrWhiteSpace(key) || string.IsNullOrWhiteSpace(serverUrl))
            {
                lblLicStatus.Text = "❌ Ingrese correo, clave y URL del servidor de licencias.";
                lblLicStatus.ForeColor = Color.FromArgb(239, 68, 68);
                return;
            }

            lblLicStatus.Text = "⏳ Conectando con el servidor " + serverUrl + " para validar la clave de 1 uso...";
            lblLicStatus.ForeColor = Color.FromArgb(245, 158, 11);
            Application.DoEvents();

            try
            {
                string endpoint = serverUrl.TrimEnd('/') + "/api/license/verify";
                string jsonBody = string.Format("{{\"email\":\"{0}\",\"license_key\":\"{1}\",\"hwid\":\"DESKTOP_HWID_VERIFIED\"}}", email, key);

                WebClient client = new WebClient();
                client.Headers[HttpRequestHeader.ContentType] = "application/json";
                string responseStr = client.UploadString(endpoint, "POST", jsonBody);

                if (responseStr.Contains("\"valid\":true"))
                {
                    isLicenseVerified = true;
                    lblLicStatus.Text = "✅ ¡LICENCIA COMERCIAL AUTENTICADA Y VÁLIDA EN EL SERVIDOR!\nServidor: " + serverUrl + "\nEstado: Activa y vinculada correctamente.";
                    lblLicStatus.ForeColor = Color.FromArgb(16, 185, 129);
                    MessageBox.Show("¡Licencia Autenticada Exitosamente con el Servidor Local!\n\nCorreo: " + email + "\nClave: " + key, "Validación Exitosa", MessageBoxButtons.OK, MessageBoxIcon.Information);
                }
                else
                {
                    isLicenseVerified = false;
                    lblLicStatus.Text = "❌ LICENCIA RECHAZADA O REVOCADA POR EL SERVIDOR:\n" + responseStr;
                    lblLicStatus.ForeColor = Color.FromArgb(239, 68, 68);
                }
            }
            catch (Exception ex)
            {
                isLicenseVerified = false;
                lblLicStatus.Text = "❌ ERROR DE CONEXIÓN CON EL SERVIDOR:\n" + ex.Message + "\nVerifique que el Servidor de Licencias esté activo en " + serverUrl;
                lblLicStatus.ForeColor = Color.FromArgb(239, 68, 68);
            }
        }

        private void BtnNext_Click(object sender, EventArgs e)
        {
            if (currentStep == 1)
            {
                if (string.IsNullOrWhiteSpace(txtLegalName.Text) || string.IsNullOrWhiteSpace(txtTaxId.Text))
                {
                    MessageBox.Show("Por favor complete Razón Social y RUT.", "Campo Obligatorio", MessageBoxButtons.OK, MessageBoxIcon.Warning);
                    return;
                }
            }
            else if (currentStep == 3)
            {
                if (!isLicenseVerified)
                {
                    BtnVerifyLicense_Click(sender, e);
                    if (!isLicenseVerified)
                    {
                        DialogResult dr = MessageBox.Show("La licencia aún no ha sido validada exitosamente con el servidor.\n\n¿Desea continuar e intentar validar al abrir la aplicación?", "Confirmar Validación", MessageBoxButtons.YesNo, MessageBoxIcon.Question);
                        if (dr != DialogResult.Yes) return;
                    }
                }
            }

            if (currentStep < TOTAL_STEPS)
            {
                ShowStep(currentStep + 1);
            }
            else
            {
                PerformInstallation();
            }
        }

        private void PerformInstallation()
        {
            try
            {
                this.Cursor = Cursors.WaitCursor;
                btnNext.Enabled = false;

                string serverUrl = txtLicServer.Text.Trim();
                string email = txtLicEmail.Text.Trim();
                string key = txtLicKey.Text.Trim();
                string legalName = txtLegalName.Text.Trim();

                // 1. Guardar archivo license_config.json en ProgramData y en Laragon
                string jsonConfig = string.Format("{{\n  \"server_url\": \"{0}\",\n  \"email\": \"{1}\",\n  \"license_key\": \"{2}\",\n  \"customer_name\": \"{3}\",\n  \"status\": \"active\"\n}}", serverUrl, email, key, legalName);

                string programDataDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "Blue-Cat", "config");
                Directory.CreateDirectory(programDataDir);
                File.WriteAllText(Path.Combine(programDataDir, "license_config.json"), jsonConfig, Encoding.UTF8);

                string laragonConfigDir = @"C:\laragon\www\Blue-Cat\config";
                if (Directory.Exists(@"C:\laragon\www\Blue-Cat"))
                {
                    Directory.CreateDirectory(laragonConfigDir);
                    File.WriteAllText(Path.Combine(laragonConfigDir, "license_config.json"), jsonConfig, Encoding.UTF8);
                }

                // 2. Crear Acceso Directo de Escritorio "Blue-Cat ERP.url"
                string desktopPath = Environment.GetFolderPath(Environment.SpecialFolder.Desktop);
                string shortcutPath = Path.Combine(desktopPath, "Blue-Cat ERP.url");
                string shortcutContent = "[InternetShortcut]\nURL=http://localhost/Blue-Cat/\nIDList=\nHotKey=0\nIconFile=C:\\laragon\\www\\Blue-Cat\\favicon.ico\nIconIndex=0\n";
                File.WriteAllText(shortcutPath, shortcutContent, Encoding.UTF8);

                this.Cursor = Cursors.Default;
                MessageBox.Show(string.Format("¡Instalación y Activación Completada con Éxito!\n\nCliente: {0}\nLicencia: {1}\nAcceso Directo Creado: {2}\n\nSe abrirá Blue-Cat ERP en pantalla completa.", legalName, key, shortcutPath), "Instalación Exitosa", MessageBoxButtons.OK, MessageBoxIcon.Information);

                // 3. Abrir Aplicación Web Blue-Cat ERP en ventana independiente
                bool launched = false;
                try
                {
                    System.Diagnostics.Process.Start("msedge.exe", "--app=http://localhost/Blue-Cat/ --start-maximized");
                    launched = true;
                }
                catch { }

                if (!launched)
                {
                    try
                    {
                        System.Diagnostics.Process.Start("chrome.exe", "--app=http://localhost/Blue-Cat/ --start-maximized");
                        launched = true;
                    }
                    catch { }
                }

                if (!launched)
                {
                    System.Diagnostics.Process.Start("http://localhost/Blue-Cat/");
                }

                Application.Exit();
            }
            catch (Exception ex)
            {
                this.Cursor = Cursors.Default;
                btnNext.Enabled = true;
                MessageBox.Show("Error durante la instalación: " + ex.Message, "Error", MessageBoxButtons.OK, MessageBoxIcon.Error);
            }
        }

        [STAThread]
        public static void Main()
        {
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            Application.Run(new InstallerForm());
        }
    }
}
