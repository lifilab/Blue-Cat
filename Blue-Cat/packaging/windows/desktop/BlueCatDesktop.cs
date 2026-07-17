using System;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.ServiceProcess;
using System.Threading.Tasks;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Interop;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using Microsoft.Win32;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.Wpf;

namespace BlueCat.Desktop
{
    internal static class Program
    {
        [STAThread]
        private static void Main(string[] args)
        {
            ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12;
            bool posMode = HasArgument(args, "--pos");
            bool diagnosticMode = HasArgument(args, "--diagnostic");
            bool fullScreen = HasArgument(args, "--fullscreen") || (!diagnosticMode && !HasArgument(args, "--windowed"));
            string target = diagnosticMode ? "https://localhost/public/diagnostico.html" : (posMode ? "https://localhost/public/pos.html" : "https://localhost/");
            bool first;
            using (var mutex = new System.Threading.Mutex(true, posMode ? "BlueCatDesktopPOS" : "BlueCatDesktop", out first))
            {
                if (!first) return;
                var app = new Application { ShutdownMode = ShutdownMode.OnMainWindowClose };
                app.Run(new BlueCatWindow(target, posMode, fullScreen));
            }
        }

        private static bool HasArgument(string[] args, string value)
        {
            foreach (string arg in args) if (string.Equals(arg, value, StringComparison.OrdinalIgnoreCase)) return true;
            return false;
        }
    }

    internal sealed class BlueCatWindow : Window
    {
        private readonly string targetUrl;
        private readonly bool posMode;
        private readonly WebView2 webView;
        private readonly Grid root;
        private WindowStyle previousStyle = WindowStyle.SingleBorderWindow;
        private WindowState previousState = WindowState.Maximized;
        private bool isFullScreen;

        internal BlueCatWindow(string target, bool isPos, bool startFullScreen)
        {
            targetUrl = target;
            posMode = isPos;
            Title = isPos ? "Blue-Cat POS" : "Blue-Cat";
            MinWidth = 960;
            MinHeight = 640;
            WindowStartupLocation = WindowStartupLocation.CenterScreen;
            Background = new SolidColorBrush(Color.FromRgb(238, 243, 249));
            TrySetIcon();
            RestoreWindow();

            root = new Grid();
            webView = new WebView2 { Visibility = Visibility.Collapsed };
            root.Children.Add(webView);
            Content = root;

            PreviewKeyDown += OnPreviewKeyDown;
            Closing += delegate { SaveWindow(); };
            Loaded += async delegate
            {
                if (startFullScreen) SetFullScreen(true);
                await InitializeAsync();
            };
        }

        private async Task InitializeAsync()
        {
            ShowMessage("Iniciando Blue-Cat…", "Comprobando servicios locales.", false);
            try
            {
                await EnsureServicesAsync();
                ShowMessage("Iniciando Blue-Cat…", "Esperando que el servidor local esté disponible.", false);
                if (!await WaitForBackendAsync())
                {
                    ShowMessage("Blue-Cat no pudo iniciar", "El servidor local no respondió. Revise si el puerto 443 está ocupado, el firewall o los servicios Blue-Cat.", true);
                    return;
                }

                string userData = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Blue-Cat", "WebView2");
                Directory.CreateDirectory(userData);
                CoreWebView2Environment environment = await CoreWebView2Environment.CreateAsync(null, userData);
                await webView.EnsureCoreWebView2Async(environment);
                webView.CoreWebView2.Settings.AreDevToolsEnabled = false;
                webView.CoreWebView2.Settings.IsStatusBarEnabled = false;
                webView.CoreWebView2.Settings.AreDefaultScriptDialogsEnabled = true;
                webView.CoreWebView2.Settings.AreDefaultContextMenusEnabled = !posMode;
                webView.CoreWebView2.NewWindowRequested += OnNewWindowRequested;
                webView.CoreWebView2.NavigationStarting += OnNavigationStarting;
                webView.NavigationCompleted += OnNavigationCompleted;
                root.Children.Clear();
                root.Children.Add(webView);
                webView.Visibility = Visibility.Visible;
                webView.Source = new Uri(targetUrl);
                webView.Focus();
            }
            catch (Exception error)
            {
                Log("No fue posible inicializar el cliente.", error);
                ShowMessage("No fue posible abrir Blue-Cat", FriendlyError(error), true);
            }
        }

        private static async Task EnsureServicesAsync()
        {
            string[] names = { "BlueCatDatabase", "BlueCatPhp", "BlueCatWeb" };
            bool needsElevation = false;
            foreach (string name in names)
            {
                using (var service = new ServiceController(name))
                {
                    try
                    {
                        service.Refresh();
                        if (service.Status == ServiceControllerStatus.Stopped)
                        {
                            service.Start();
                            service.WaitForStatus(ServiceControllerStatus.Running, TimeSpan.FromSeconds(30));
                        }
                    }
                    catch { needsElevation = true; break; }
                }
            }
            if (needsElevation)
            {
                var start = new ProcessStartInfo
                {
                    FileName = "powershell.exe",
                    Arguments = "-NoProfile -ExecutionPolicy Bypass -Command \"Start-Service BlueCatDatabase; Start-Service BlueCatPhp; Start-Service BlueCatWeb\"",
                    Verb = "runas",
                    UseShellExecute = true,
                    WindowStyle = ProcessWindowStyle.Hidden
                };
                using (Process process = Process.Start(start)) { process.WaitForExit(); }
            }
            await Task.Delay(250);
        }

        private static async Task<bool> WaitForBackendAsync()
        {
            DateTime deadline = DateTime.UtcNow.AddSeconds(45);
            Exception lastError = null;
            while (DateTime.UtcNow < deadline)
            {
                try
                {
                    var request = (HttpWebRequest)WebRequest.Create("https://localhost/assets/api/health.php");
                    request.Method = "GET";
                    request.Timeout = 2500;
                    request.ReadWriteTimeout = 2500;
                    Task<WebResponse> responseTask = request.GetResponseAsync();
                    if (await Task.WhenAny(responseTask, Task.Delay(3000)) != responseTask)
                    {
                        request.Abort();
                        throw new System.TimeoutException("El health check local superó 3 segundos.");
                    }
                    using (var response = (HttpWebResponse)await responseTask)
                    using (var reader = new StreamReader(response.GetResponseStream()))
                    {
                        string body = await reader.ReadToEndAsync();
                        if (response.StatusCode == HttpStatusCode.OK && body.Contains("\"status\":\"ok\"")) return true;
                    }
                }
                catch (Exception error) { lastError = error; }
                await Task.Delay(750);
            }
            Log("El backend local no respondió dentro del plazo.", lastError);
            return false;
        }

        private static void Log(string message, Exception error)
        {
            try
            {
                string directory = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Blue-Cat", "logs");
                Directory.CreateDirectory(directory);
                string detail = error == null ? "" : " | " + error.GetType().Name + ": " + error.Message;
                File.AppendAllText(Path.Combine(directory, "desktop.log"), DateTime.UtcNow.ToString("o") + " | " + message + detail + Environment.NewLine, System.Text.Encoding.UTF8);
            }
            catch { }
        }

        private void OnNewWindowRequested(object sender, CoreWebView2NewWindowRequestedEventArgs e)
        {
            e.Handled = true;
            Uri uri;
            if (Uri.TryCreate(e.Uri, UriKind.Absolute, out uri) && IsInternal(uri)) webView.Source = uri;
            else if (Uri.TryCreate(e.Uri, UriKind.Absolute, out uri)) Process.Start(new ProcessStartInfo(uri.AbsoluteUri) { UseShellExecute = true });
        }

        private void OnNavigationStarting(object sender, CoreWebView2NavigationStartingEventArgs e)
        {
            Uri uri;
            if (!Uri.TryCreate(e.Uri, UriKind.Absolute, out uri) || IsInternal(uri)) return;
            e.Cancel = true;
            Process.Start(new ProcessStartInfo(uri.AbsoluteUri) { UseShellExecute = true });
        }

        private void OnNavigationCompleted(object sender, CoreWebView2NavigationCompletedEventArgs e)
        {
            if (!e.IsSuccess) ShowMessage("No se pudo cargar la pantalla", "El servidor respondió, pero la interfaz no pudo abrirse. Código: " + e.WebErrorStatus, true);
        }

        private static bool IsInternal(Uri uri)
        {
            return uri.Scheme == Uri.UriSchemeHttps && string.Equals(uri.Host, "localhost", StringComparison.OrdinalIgnoreCase);
        }

        private void ShowMessage(string title, string detail, bool retry)
        {
            root.Children.Clear();
            var panel = new StackPanel { Width = 560, VerticalAlignment = VerticalAlignment.Center, HorizontalAlignment = HorizontalAlignment.Center };
            panel.Children.Add(new TextBlock { Text = title, FontSize = 28, FontWeight = FontWeights.SemiBold, Foreground = new SolidColorBrush(Color.FromRgb(15, 34, 64)), TextAlignment = TextAlignment.Center, Margin = new Thickness(0, 0, 0, 14) });
            panel.Children.Add(new TextBlock { Text = detail, FontSize = 16, Foreground = new SolidColorBrush(Color.FromRgb(65, 82, 110)), TextWrapping = TextWrapping.Wrap, TextAlignment = TextAlignment.Center, Margin = new Thickness(0, 0, 0, 22) });
            if (retry)
            {
                var button = new Button { Content = "Reintentar", Width = 150, Height = 42, FontSize = 15, HorizontalAlignment = HorizontalAlignment.Center };
                button.Click += async delegate { await InitializeAsync(); };
                panel.Children.Add(button);
            }
            root.Children.Add(panel);
        }

        private static string FriendlyError(Exception error)
        {
            if (error.Message.IndexOf("WebView2", StringComparison.OrdinalIgnoreCase) >= 0)
                return "Falta o está dañado Microsoft WebView2 Runtime. Ejecute Reparar desde Aplicaciones instaladas.";
            if (error is System.ComponentModel.Win32Exception)
                return "Windows no autorizó iniciar los servicios. Acepte la solicitud de administrador o ejecute Reparar.";
            return "Revise los servicios Blue-Cat, el puerto 443 y el panel de diagnóstico. Detalle: " + error.Message;
        }

        private void OnPreviewKeyDown(object sender, KeyEventArgs e)
        {
            if (e.Key == Key.F11) { SetFullScreen(!isFullScreen); e.Handled = true; }
            if (e.Key == Key.Escape && isFullScreen) { SetFullScreen(false); e.Handled = true; }
        }

        private void SetFullScreen(bool enabled)
        {
            if (enabled == isFullScreen) return;
            if (enabled)
            {
                previousStyle = WindowStyle;
                previousState = WindowState;
                WindowStyle = WindowStyle.None;
                WindowState = WindowState.Maximized;
            }
            else
            {
                WindowStyle = previousStyle;
                WindowState = previousState;
            }
            isFullScreen = enabled;
        }

        private void RestoreWindow()
        {
            using (RegistryKey key = Registry.CurrentUser.OpenSubKey("Software\\Lifilab\\Blue-Cat"))
            {
                if (key != null)
                {
                    double value;
                    if (double.TryParse(Convert.ToString(key.GetValue("Width")), out value) && value >= MinWidth) Width = value;
                    if (double.TryParse(Convert.ToString(key.GetValue("Height")), out value) && value >= MinHeight) Height = value;
                }
            }
            WindowState = WindowState.Maximized;
        }

        private void SaveWindow()
        {
            Rect bounds = RestoreBounds;
            using (RegistryKey key = Registry.CurrentUser.CreateSubKey("Software\\Lifilab\\Blue-Cat"))
            {
                key.SetValue("Width", bounds.Width.ToString(System.Globalization.CultureInfo.InvariantCulture));
                key.SetValue("Height", bounds.Height.ToString(System.Globalization.CultureInfo.InvariantCulture));
            }
        }

        private void TrySetIcon()
        {
            try
            {
                using (System.Drawing.Icon icon = System.Drawing.Icon.ExtractAssociatedIcon(Process.GetCurrentProcess().MainModule.FileName))
                {
                    Icon = Imaging.CreateBitmapSourceFromHIcon(icon.Handle, Int32Rect.Empty, BitmapSizeOptions.FromEmptyOptions());
                }
            }
            catch { }
        }
    }
}
