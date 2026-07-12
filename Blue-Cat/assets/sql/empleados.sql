-- Empleados - DDL
SET NAMES utf8mb4;

DROP TABLE IF EXISTS empleado_auditoria;
DROP TABLE IF EXISTS empleado_historial;
DROP TABLE IF EXISTS empleado_activo;
DROP TABLE IF EXISTS empleado_evaluacion;
DROP TABLE IF EXISTS empleado_capacitacion;
DROP TABLE IF EXISTS empleado_beneficio;
DROP TABLE IF EXISTS empleado_remuneracion;
DROP TABLE IF EXISTS empleado_hora_extra;
DROP TABLE IF EXISTS empleado_licencia;
DROP TABLE IF EXISTS empleado_permiso;
DROP TABLE IF EXISTS empleado_vacacion;
DROP TABLE IF EXISTS empleado_asistencia;
DROP TABLE IF EXISTS empleado_turno;
DROP TABLE IF EXISTS empleado_documento;
DROP TABLE IF EXISTS empleado_contrato;
DROP TABLE IF EXISTS empleado;

CREATE TABLE empleado (
  id_empleado INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  codigo VARCHAR(20) NOT NULL,
  rut VARCHAR(20),
  nombres VARCHAR(100) NOT NULL,
  apellidos VARCHAR(100) NOT NULL,
  fecha_nacimiento DATE,
  sexo VARCHAR(10),
  estado_civil VARCHAR(30),
  nacionalidad VARCHAR(50) DEFAULT 'Chilena',
  fotografia VARCHAR(255),
  correo_personal VARCHAR(100),
  correo_corporativo VARCHAR(100),
  telefono VARCHAR(30),
  celular VARCHAR(30),
  direccion VARCHAR(200),
  comuna VARCHAR(60),
  ciudad VARCHAR(60),
  region VARCHAR(60),
  pais VARCHAR(60) DEFAULT 'Chile',
  contacto_emergencia_nombre VARCHAR(100),
  contacto_emergencia_telefono VARCHAR(30),
  cargo VARCHAR(100),
  departamento VARCHAR(100),
  sucursal VARCHAR(100),
  centro_costo VARCHAR(100),
  jefe_directo VARCHAR(100),
  fecha_ingreso DATE,
  fecha_termino DATE,
  tipo_contrato VARCHAR(50),
  modalidad VARCHAR(30),
  horario VARCHAR(100),
  id_turno INT,
  sueldo_base INT DEFAULT 0,
  asignaciones INT DEFAULT 0,
  bonos INT DEFAULT 0,
  comisiones INT DEFAULT 0,
  afp VARCHAR(50),
  salud VARCHAR(50),
  caja_compensacion VARCHAR(50),
  mutual VARCHAR(50),
  banco VARCHAR(50),
  tipo_cuenta VARCHAR(30),
  numero_cuenta VARCHAR(40),
  forma_pago VARCHAR(30),
  tramo_impuesto VARCHAR(50),
  retenciones TEXT,
  estado VARCHAR(30) DEFAULT 'ACTIVO',
  observaciones TEXT,
  ultimo_acceso DATETIME,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_user) REFERENCES usuario(id_user)
);

CREATE TABLE empleado_contrato (
  id_contrato INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT NOT NULL,
  tipo VARCHAR(50),
  fecha_inicio DATE,
  fecha_termino DATE,
  sueldo_base INT DEFAULT 0,
  asignaciones INT DEFAULT 0,
  bonos INT DEFAULT 0,
  archivo VARCHAR(255),
  notas TEXT,
  estado VARCHAR(20) DEFAULT 'ACTIVO',
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado)
);

CREATE TABLE empleado_documento (
  id_documento INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT NOT NULL,
  tipo VARCHAR(50),
  nombre VARCHAR(200),
  archivo VARCHAR(255),
  fecha_emision DATE,
  fecha_vencimiento DATE,
  estado VARCHAR(20) DEFAULT 'VIGENTE',
  notas TEXT,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado)
);

CREATE TABLE empleado_turno (
  id_turno INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT,
  nombre VARCHAR(60),
  hora_inicio TIME,
  hora_fin TIME,
  dias_semana VARCHAR(50),
  color VARCHAR(7) DEFAULT '#4f46e5',
  activo TINYINT DEFAULT 1,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE empleado_asistencia (
  id_asistencia INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT NOT NULL,
  fecha DATE NOT NULL,
  entrada TIME,
  salida TIME,
  colacion TIME,
  horas_trabajadas DECIMAL(5,2),
  horas_extra DECIMAL(5,2),
  retraso INT DEFAULT 0,
  tipo VARCHAR(30) DEFAULT 'NORMAL',
  observaciones TEXT,
  FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado),
  UNIQUE KEY uq_asistencia (id_empleado, fecha)
);

CREATE TABLE empleado_vacacion (
  id_vacacion INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT NOT NULL,
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NOT NULL,
  dias INT NOT NULL,
  tipo VARCHAR(30) DEFAULT 'PROGRESIVAS',
  estado VARCHAR(20) DEFAULT 'PENDIENTE',
  aprobado_por INT,
  comentarios TEXT,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado)
);

CREATE TABLE empleado_permiso (
  id_permiso INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT NOT NULL,
  tipo VARCHAR(50),
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NOT NULL,
  horas INT DEFAULT 0,
  motivo TEXT,
  estado VARCHAR(20) DEFAULT 'PENDIENTE',
  aprobado_por INT,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado)
);

CREATE TABLE empleado_licencia (
  id_licencia INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT NOT NULL,
  tipo VARCHAR(50),
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NOT NULL,
  diagnostico VARCHAR(255),
  entidad_emisora VARCHAR(100),
  folio VARCHAR(50),
  estado VARCHAR(20) DEFAULT 'ACTIVA',
  subsidio INT DEFAULT 0,
  archivo VARCHAR(255),
  FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado)
);

CREATE TABLE empleado_hora_extra (
  id_hora_extra INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT NOT NULL,
  fecha DATE NOT NULL,
  cantidad DECIMAL(5,2) NOT NULL,
  motivo TEXT,
  aprobado_por INT,
  estado VARCHAR(20) DEFAULT 'PENDIENTE',
  pago INT DEFAULT 0,
  compensacion TINYINT DEFAULT 0,
  FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado)
);

CREATE TABLE empleado_remuneracion (
  id_remuneracion INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT NOT NULL,
  periodo VARCHAR(7) NOT NULL,
  sueldo_base INT DEFAULT 0,
  bonificaciones INT DEFAULT 0,
  comisiones INT DEFAULT 0,
  horas_extra INT DEFAULT 0,
  descuentos INT DEFAULT 0,
  anticipos INT DEFAULT 0,
  liquido INT DEFAULT 0,
  archivo_pdf VARCHAR(255),
  pagado TINYINT DEFAULT 0,
  fecha_pago DATE,
  FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado)
);

CREATE TABLE empleado_beneficio (
  id_beneficio INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT NOT NULL,
  tipo VARCHAR(50),
  descripcion TEXT,
  monto INT DEFAULT 0,
  vigencia DATE,
  estado VARCHAR(20) DEFAULT 'ACTIVO',
  FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado)
);

CREATE TABLE empleado_capacitacion (
  id_capacitacion INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT NOT NULL,
  curso VARCHAR(200) NOT NULL,
  proveedor VARCHAR(100),
  fecha DATE,
  horas INT DEFAULT 0,
  costo INT DEFAULT 0,
  estado VARCHAR(30) DEFAULT 'PENDIENTE',
  certificado VARCHAR(255),
  vencimiento DATE,
  renovacion TINYINT DEFAULT 0,
  FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado)
);

CREATE TABLE empleado_evaluacion (
  id_evaluacion INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT NOT NULL,
  periodo VARCHAR(20),
  fecha DATE,
  competencias INT DEFAULT 0,
  objetivos INT DEFAULT 0,
  productividad INT DEFAULT 0,
  trabajo_equipo INT DEFAULT 0,
  puntualidad INT DEFAULT 0,
  responsabilidad INT DEFAULT 0,
  calidad INT DEFAULT 0,
  puntaje_total INT DEFAULT 0,
  comentarios TEXT,
  plan_mejora TEXT,
  evaluador VARCHAR(100),
  FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado)
);

CREATE TABLE empleado_activo (
  id_activo INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT NOT NULL,
  tipo VARCHAR(50),
  codigo_activo VARCHAR(50),
  descripcion VARCHAR(200),
  fecha_entrega DATE,
  fecha_devolucion DATE,
  estado VARCHAR(30) DEFAULT 'ASIGNADO',
  responsable VARCHAR(100),
  observaciones TEXT,
  FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado)
);

CREATE TABLE empleado_historial (
  id_historial INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT NOT NULL,
  tipo VARCHAR(50),
  fecha DATE,
  valor_anterior TEXT,
  valor_nuevo TEXT,
  descripcion TEXT,
  FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado)
);

CREATE TABLE empleado_auditoria (
  id_auditoria INT AUTO_INCREMENT PRIMARY KEY,
  id_empleado INT,
  id_user INT,
  accion VARCHAR(50),
  detalle TEXT,
  ip VARCHAR(45),
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
