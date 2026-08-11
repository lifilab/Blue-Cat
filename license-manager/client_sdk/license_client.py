"""
SDK / Script Cliente de Demostración - Validación Online de Licencia Anti-Keygens
Este script simula el software vendido al cliente final.
Requiere conexión constante al servidor de licencias.
"""

import json
import time
import uuid
import sys
import threading
import urllib.request
import urllib.parse
import os

CONFIG_FILE = os.path.join(os.path.dirname(__file__), "license_config.json")

class LicenseManagerClient:
    def __init__(self, config_path=CONFIG_FILE):
        self.config_path = config_path
        self.server_url = None
        self.email = None
        self.license_key = None
        self.session_token = None
        self.hwid = self._get_hwid()
        self.is_valid = False
        self.stop_heartbeat = False
        self.heartbeat_thread = None

        self._load_config()

    def _get_hwid(self):
        """Genera una huella digital única (HWID) del equipo cliente."""
        return str(uuid.getnode())

    def _load_config(self):
        if os.path.exists(self.config_path):
            try:
                with open(self.config_path, 'r', encoding='utf-8') as f:
                    data = json.load(f)
                    self.server_url = data.get("server_url", "http://localhost:3050")
                    self.email = data.get("email")
                    self.license_key = data.get("license_key")
            except Exception as e:
                print(f"[!] Error leyendo license_config.json: {e}")
        
        if not self.email or not self.license_key:
            print("=================================================")
            print("  CONFIGURACIÓN DE LICENCIA DEL CLIENTE")
            print("=================================================")
            self.server_url = self.server_url or input("URL del Servidor [http://localhost:3050]: ").strip() or "http://localhost:3050"
            self.email = input("Tu Correo Electrónico Registrado: ").strip()
            self.license_key = input("Tu Clave de Licencia (XXXX-XXXX-XXXX-XXXX): ").strip()

    def verify_and_start(self):
        """Inicia el handshake de autenticación con el servidor."""
        print(f"\n[*] Conectando al servidor {self.server_url}...")
        payload = {
            "email": self.email,
            "license_key": self.license_key,
            "hwid": self.hwid
        }

        try:
            req = urllib.request.Request(
                f"{self.server_url}/api/license/verify",
                data=json.dumps(payload).encode('utf-8'),
                headers={'Content-Type': 'application/json'}
            )
            with urllib.request.urlopen(req, timeout=10) as response:
                res = json.loads(response.read().decode('utf-8'))
                
                if res.get("status") == "success":
                    self.session_token = res.get("session_token")
                    self.is_valid = True
                    print("=================================================")
                    print(f"  [✓] LICENCIA VERIFICADA Y ACTIVA")
                    print(f"  Cliente: {res.get('client_name')}")
                    print(f"  ID de Sesión Dinámica: {self.session_token[:15]}...")
                    print("=================================================")
                    
                    # Iniciar el hilo de heartbeat (ping constante al servidor)
                    self._start_heartbeat_loop()
                    return True
                else:
                    print(f"\n[X] ERROR DE LICENCIA: {res.get('message')}")
                    return False

        except urllib.error.HTTPError as e:
            err_body = json.loads(e.read().decode('utf-8'))
            print(f"\n[X] ACCESO DENEGADO (HTTP {e.code}): {err_body.get('message', 'Error desconocido')}")
            return False
        except Exception as e:
            print(f"\n[X] ERROR DE CONEXIÓN AL SERVIDOR: {e}")
            print("Para que el programa funcione, debes estar constantemente conectado a internet/servidor.")
            return False

    def _start_heartbeat_loop(self):
        """Inicia un hilo en segundo plano para enviar pings regulares al servidor."""
        self.stop_heartbeat = False
        self.heartbeat_thread = threading.Thread(target=self._heartbeat_worker, daemon=True)
        self.heartbeat_thread.start()

    def _heartbeat_worker(self):
        while not self.stop_heartbeat and self.is_valid:
            time.sleep(15) # Ping cada 15 segundos
            
            payload = {
                "session_token": self.session_token,
                "license_key": self.license_key,
                "hwid": self.hwid
            }

            try:
                req = urllib.request.Request(
                    f"{self.server_url}/api/license/heartbeat",
                    data=json.dumps(payload).encode('utf-8'),
                    headers={'Content-Type': 'application/json'}
                )
                with urllib.request.urlopen(req, timeout=8) as response:
                    res = json.loads(response.read().decode('utf-8'))
                    if res.get("status") == "ok":
                        # Actualizar la clave dinámica de sesión con el nuevo token rotado
                        self.session_token = res.get("next_session_token")
                        # print(f"[Heartbeat OK] Token Rotado -> {self.session_token[:15]}...")
                    else:
                        print("\n[!] ALERTA: Licencia suspendida o revocada por el servidor.")
                        self._trigger_kill_switch()
                        break
            except Exception as e:
                print(f"\n[!] ALERTA DE CONEXIÓN: Fallo al comunicarse con el servidor ({e}).")
                print("[!] Reintentando conexión...")
                # En producción se da un margen de tolerancia (ej: 3 reintentos), aquí forzamos o notificamos
                self._trigger_kill_switch()
                break

    def _trigger_kill_switch(self):
        """Bloqueo de seguridad inmediato en caso de revocación o pérdida de conexión."""
        self.is_valid = False
        print("\n=================================================")
        print("  [X] PROGRAMA BLOQUEADO / LICENCIA REVOCADA")
        print("  Se ha detectado una anomalía o revocación de")
        print("  licencia desde el Panel Administrador.")
        print("=================================================")
        os._exit(1)


# ==========================================
# DEMOSTRACIÓN DE EJECUCIÓN DEL SOFTWARE
# ==========================================
if __name__ == "__main__":
    client = LicenseManagerClient()
    if client.verify_and_start():
        print("\n[+] El programa se está ejecutando normalmente...")
        print("[+] Presiona Ctrl+C para salir.")
        
        try:
            counter = 0
            while True:
                counter += 1
                time.sleep(2)
                print(f" -> [Software Activo] Procesando tarea #{counter}... (Manteniendo conexión al servidor)")
        except KeyboardInterrupt:
            print("\nSaliendo del programa...")
            sys.exit(0)
