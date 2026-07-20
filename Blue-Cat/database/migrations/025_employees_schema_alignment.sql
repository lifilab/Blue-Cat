-- Sprint 1: alinea el esquema canónico de empleados con la API activa.
-- Las migraciones anteriores son inmutables; cada ALTER es reejecutable en
-- instalaciones legacy mediante information_schema + PREPARE.
SET NAMES utf8mb4;
START TRANSACTION;

SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='codigo')=0,'ALTER TABLE empleado ADD COLUMN codigo VARCHAR(30) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='fecha_nacimiento')=0,'ALTER TABLE empleado ADD COLUMN fecha_nacimiento DATE NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='sexo')=0,'ALTER TABLE empleado ADD COLUMN sexo VARCHAR(30) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='estado_civil')=0,'ALTER TABLE empleado ADD COLUMN estado_civil VARCHAR(30) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='nacionalidad')=0,'ALTER TABLE empleado ADD COLUMN nacionalidad VARCHAR(80) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='fotografia')=0,'ALTER TABLE empleado ADD COLUMN fotografia MEDIUMTEXT NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='correo_personal')=0,'ALTER TABLE empleado ADD COLUMN correo_personal VARCHAR(190) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='correo_corporativo')=0,'ALTER TABLE empleado ADD COLUMN correo_corporativo VARCHAR(190) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='celular')=0,'ALTER TABLE empleado ADD COLUMN celular VARCHAR(30) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='comuna')=0,'ALTER TABLE empleado ADD COLUMN comuna VARCHAR(100) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='ciudad')=0,'ALTER TABLE empleado ADD COLUMN ciudad VARCHAR(100) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='region')=0,'ALTER TABLE empleado ADD COLUMN region VARCHAR(100) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='pais')=0,"ALTER TABLE empleado ADD COLUMN pais VARCHAR(80) NULL DEFAULT 'Chile'",'DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='contacto_emergencia_nombre')=0,'ALTER TABLE empleado ADD COLUMN contacto_emergencia_nombre VARCHAR(160) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='contacto_emergencia_telefono')=0,'ALTER TABLE empleado ADD COLUMN contacto_emergencia_telefono VARCHAR(30) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='sucursal')=0,'ALTER TABLE empleado ADD COLUMN sucursal VARCHAR(120) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='centro_costo')=0,'ALTER TABLE empleado ADD COLUMN centro_costo VARCHAR(100) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='jefe_directo')=0,'ALTER TABLE empleado ADD COLUMN jefe_directo VARCHAR(160) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='fecha_termino')=0,'ALTER TABLE empleado ADD COLUMN fecha_termino DATE NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='tipo_contrato')=0,'ALTER TABLE empleado ADD COLUMN tipo_contrato VARCHAR(60) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='modalidad')=0,'ALTER TABLE empleado ADD COLUMN modalidad VARCHAR(60) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='horario')=0,'ALTER TABLE empleado ADD COLUMN horario VARCHAR(120) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='sueldo_base')=0,'ALTER TABLE empleado ADD COLUMN sueldo_base BIGINT NOT NULL DEFAULT 0','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='asignaciones')=0,'ALTER TABLE empleado ADD COLUMN asignaciones BIGINT NOT NULL DEFAULT 0','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='bonos')=0,'ALTER TABLE empleado ADD COLUMN bonos BIGINT NOT NULL DEFAULT 0','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='comisiones')=0,'ALTER TABLE empleado ADD COLUMN comisiones BIGINT NOT NULL DEFAULT 0','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='afp')=0,'ALTER TABLE empleado ADD COLUMN afp VARCHAR(80) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='salud')=0,'ALTER TABLE empleado ADD COLUMN salud VARCHAR(80) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='caja_compensacion')=0,'ALTER TABLE empleado ADD COLUMN caja_compensacion VARCHAR(100) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='mutual')=0,'ALTER TABLE empleado ADD COLUMN mutual VARCHAR(100) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='banco')=0,'ALTER TABLE empleado ADD COLUMN banco VARCHAR(100) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='tipo_cuenta')=0,'ALTER TABLE empleado ADD COLUMN tipo_cuenta VARCHAR(60) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='numero_cuenta')=0,'ALTER TABLE empleado ADD COLUMN numero_cuenta VARCHAR(80) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='forma_pago')=0,'ALTER TABLE empleado ADD COLUMN forma_pago VARCHAR(60) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='tramo_impuesto')=0,'ALTER TABLE empleado ADD COLUMN tramo_impuesto VARCHAR(60) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='retenciones')=0,'ALTER TABLE empleado ADD COLUMN retenciones TEXT NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND COLUMN_NAME='observaciones')=0,'ALTER TABLE empleado ADD COLUMN observaciones TEXT NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;

UPDATE empleado SET codigo=CONCAT('EMP-',LPAD(id_empleado,6,'0')) WHERE codigo IS NULL OR codigo='';
UPDATE empleado SET correo_corporativo=correo WHERE correo_corporativo IS NULL AND correo IS NOT NULL;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND INDEX_NAME='uq_empleado_cuenta_codigo')=0,'ALTER TABLE empleado ADD UNIQUE KEY uq_empleado_cuenta_codigo (id_cuenta,codigo)','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado' AND INDEX_NAME='idx_empleado_cuenta_rut')=0,'ALTER TABLE empleado ADD KEY idx_empleado_cuenta_rut (id_cuenta,rut)','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;

-- Compatibilidad con el contrato mínimo creado por 005_empleados.sql.
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado_contrato' AND COLUMN_NAME='tipo')=0,'ALTER TABLE empleado_contrato ADD COLUMN tipo VARCHAR(60) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado_contrato' AND COLUMN_NAME='fecha_termino')=0,'ALTER TABLE empleado_contrato ADD COLUMN fecha_termino DATE NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado_contrato' AND COLUMN_NAME='sueldo_base')=0,'ALTER TABLE empleado_contrato ADD COLUMN sueldo_base BIGINT NOT NULL DEFAULT 0','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado_contrato' AND COLUMN_NAME='asignaciones')=0,'ALTER TABLE empleado_contrato ADD COLUMN asignaciones BIGINT NOT NULL DEFAULT 0','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado_contrato' AND COLUMN_NAME='bonos')=0,'ALTER TABLE empleado_contrato ADD COLUMN bonos BIGINT NOT NULL DEFAULT 0','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado_contrato' AND COLUMN_NAME='archivo')=0,'ALTER TABLE empleado_contrato ADD COLUMN archivo MEDIUMTEXT NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado_contrato' AND COLUMN_NAME='notas')=0,'ALTER TABLE empleado_contrato ADD COLUMN notas TEXT NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado_contrato' AND COLUMN_NAME='estado')=0,"ALTER TABLE empleado_contrato ADD COLUMN estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO'",'DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado_contrato' AND COLUMN_NAME='fecha_creacion')=0,'ALTER TABLE empleado_contrato ADD COLUMN fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
UPDATE empleado_contrato SET tipo=tipo_contrato WHERE tipo IS NULL AND tipo_contrato IS NOT NULL;
UPDATE empleado_contrato SET fecha_termino=fecha_fin WHERE fecha_termino IS NULL AND fecha_fin IS NOT NULL;
UPDATE empleado_contrato SET sueldo_base=salario WHERE sueldo_base=0 AND salario IS NOT NULL;
UPDATE empleado_contrato SET notas=observaciones WHERE notas IS NULL AND observaciones IS NOT NULL;
SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado_contrato' AND COLUMN_NAME='tipo_contrato' AND IS_NULLABLE='NO')=1,'ALTER TABLE empleado_contrato MODIFY tipo_contrato VARCHAR(50) NULL','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;

SET @ddl=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='empleado_auditoria' AND COLUMN_NAME='fecha')=0,'ALTER TABLE empleado_auditoria ADD COLUMN fecha DATETIME NULL DEFAULT CURRENT_TIMESTAMP','DO 0'); PREPARE s1_stmt FROM @ddl; EXECUTE s1_stmt; DEALLOCATE PREPARE s1_stmt;
UPDATE empleado_auditoria SET fecha=created_at WHERE fecha IS NULL;

CREATE TABLE IF NOT EXISTS empleado_documento (
  id_documento INT AUTO_INCREMENT PRIMARY KEY, id_empleado INT NOT NULL,
  tipo VARCHAR(80) NULL, nombre VARCHAR(190) NOT NULL, archivo MEDIUMTEXT NULL,
  fecha_emision DATE NULL, fecha_vencimiento DATE NULL, notas TEXT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO', fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_empleado_documento (id_empleado,fecha_creacion),
  CONSTRAINT fk_empleado_documento_empleado FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empleado_turno (
  id_turno INT AUTO_INCREMENT PRIMARY KEY, id_empleado INT NOT NULL,
  nombre VARCHAR(100) NOT NULL, hora_inicio TIME NOT NULL, hora_fin TIME NOT NULL,
  dias_semana VARCHAR(30) NOT NULL, color VARCHAR(20) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1, fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_empleado_turno (id_empleado,activo),
  CONSTRAINT fk_empleado_turno_empleado FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empleado_asistencia (
  id_asistencia INT AUTO_INCREMENT PRIMARY KEY, id_empleado INT NOT NULL,
  fecha DATE NOT NULL, entrada TIME NULL, salida TIME NULL, colacion VARCHAR(30) NULL,
  horas_trabajadas DECIMAL(8,3) NULL, horas_extra DECIMAL(8,3) NULL,
  retraso INT NOT NULL DEFAULT 0, tipo VARCHAR(30) NOT NULL DEFAULT 'NORMAL', observaciones TEXT NULL,
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_empleado_asistencia_fecha (id_empleado,fecha),
  CONSTRAINT fk_empleado_asistencia_empleado FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empleado_vacacion (
  id_vacacion INT AUTO_INCREMENT PRIMARY KEY, id_empleado INT NOT NULL,
  fecha_inicio DATE NOT NULL, fecha_fin DATE NOT NULL, dias INT NOT NULL,
  tipo VARCHAR(60) NULL, comentarios TEXT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
  aprobado_por INT NULL, fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_empleado_vacacion (id_empleado,estado,fecha_inicio),
  CONSTRAINT fk_empleado_vacacion_empleado FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empleado_permiso (
  id_permiso INT AUTO_INCREMENT PRIMARY KEY, id_empleado INT NOT NULL,
  tipo VARCHAR(60) NOT NULL, fecha_inicio DATE NOT NULL, fecha_fin DATE NOT NULL,
  horas INT NOT NULL DEFAULT 0, motivo TEXT NULL, estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
  aprobado_por INT NULL, fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_empleado_permiso (id_empleado,estado,fecha_inicio),
  CONSTRAINT fk_empleado_permiso_empleado FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empleado_licencia (
  id_licencia INT AUTO_INCREMENT PRIMARY KEY, id_empleado INT NOT NULL,
  tipo VARCHAR(60) NOT NULL, fecha_inicio DATE NOT NULL, fecha_fin DATE NOT NULL,
  diagnostico TEXT NULL, entidad_emisora VARCHAR(190) NULL, folio VARCHAR(100) NULL,
  subsidio BIGINT NOT NULL DEFAULT 0, archivo MEDIUMTEXT NULL,
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_empleado_licencia (id_empleado,fecha_inicio),
  CONSTRAINT fk_empleado_licencia_empleado FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empleado_hora_extra (
  id_hora_extra INT AUTO_INCREMENT PRIMARY KEY, id_empleado INT NOT NULL,
  fecha DATE NOT NULL, cantidad DECIMAL(8,3) NOT NULL, motivo TEXT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE', aprobado_por INT NULL,
  pago BIGINT NOT NULL DEFAULT 0, compensacion TINYINT(1) NOT NULL DEFAULT 0,
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_empleado_hora_extra (id_empleado,estado,fecha),
  CONSTRAINT fk_empleado_hora_extra_empleado FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empleado_remuneracion (
  id_remuneracion INT AUTO_INCREMENT PRIMARY KEY, id_empleado INT NOT NULL,
  periodo VARCHAR(20) NOT NULL, sueldo_base BIGINT NOT NULL DEFAULT 0,
  bonificaciones BIGINT NOT NULL DEFAULT 0, comisiones BIGINT NOT NULL DEFAULT 0,
  horas_extra BIGINT NOT NULL DEFAULT 0, descuentos BIGINT NOT NULL DEFAULT 0,
  anticipos BIGINT NOT NULL DEFAULT 0, liquido BIGINT NOT NULL DEFAULT 0,
  archivo_pdf MEDIUMTEXT NULL, fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_empleado_remuneracion_periodo (id_empleado,periodo),
  CONSTRAINT fk_empleado_remuneracion_empleado FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empleado_beneficio (
  id_beneficio INT AUTO_INCREMENT PRIMARY KEY, id_empleado INT NOT NULL,
  tipo VARCHAR(80) NOT NULL, descripcion TEXT NULL, monto BIGINT NOT NULL DEFAULT 0,
  vigencia DATE NULL, estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_empleado_beneficio (id_empleado,estado),
  CONSTRAINT fk_empleado_beneficio_empleado FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empleado_capacitacion (
  id_capacitacion INT AUTO_INCREMENT PRIMARY KEY, id_empleado INT NOT NULL,
  curso VARCHAR(190) NOT NULL, proveedor VARCHAR(190) NULL, fecha DATE NULL,
  horas INT NOT NULL DEFAULT 0, costo BIGINT NOT NULL DEFAULT 0,
  certificado MEDIUMTEXT NULL, vencimiento DATE NULL, renovacion TINYINT(1) NOT NULL DEFAULT 0,
  estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE', fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_empleado_capacitacion (id_empleado,estado,fecha),
  CONSTRAINT fk_empleado_capacitacion_empleado FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empleado_evaluacion (
  id_evaluacion INT AUTO_INCREMENT PRIMARY KEY, id_empleado INT NOT NULL,
  periodo VARCHAR(60) NULL, fecha DATE NOT NULL, competencias INT NOT NULL DEFAULT 0,
  objetivos INT NOT NULL DEFAULT 0, productividad INT NOT NULL DEFAULT 0,
  trabajo_equipo INT NOT NULL DEFAULT 0, puntualidad INT NOT NULL DEFAULT 0,
  responsabilidad INT NOT NULL DEFAULT 0, calidad INT NOT NULL DEFAULT 0,
  puntaje_total INT NOT NULL DEFAULT 0, comentarios TEXT NULL, plan_mejora TEXT NULL,
  evaluador VARCHAR(190) NULL, fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_empleado_evaluacion (id_empleado,fecha),
  CONSTRAINT fk_empleado_evaluacion_empleado FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empleado_activo (
  id_activo INT AUTO_INCREMENT PRIMARY KEY, id_empleado INT NOT NULL,
  tipo VARCHAR(80) NOT NULL, codigo_activo VARCHAR(100) NULL, descripcion TEXT NULL,
  fecha_entrega DATE NOT NULL, responsable VARCHAR(190) NULL, observaciones TEXT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'ASIGNADO', fecha_devolucion DATE NULL,
  fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_empleado_activo (id_empleado,estado),
  CONSTRAINT fk_empleado_activo_empleado FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empleado_historial (
  id_historial INT AUTO_INCREMENT PRIMARY KEY, id_empleado INT NOT NULL,
  tipo VARCHAR(80) NOT NULL, fecha DATE NOT NULL, valor_anterior TEXT NULL,
  valor_nuevo TEXT NULL, descripcion TEXT NULL, fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_empleado_historial (id_empleado,fecha),
  CONSTRAINT fk_empleado_historial_empleado FOREIGN KEY (id_empleado) REFERENCES empleado(id_empleado) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La plantilla Supervisor no existía en 011, aunque 015/019/021 le asignan permisos.
INSERT INTO rol (id_cuenta,nombre,descripcion,es_sistema,es_plantilla,activo)
SELECT NULL,'Supervisor','Autoriza excepciones de POS e inventario',1,1,1
WHERE NOT EXISTS (SELECT 1 FROM rol WHERE id_cuenta IS NULL AND nombre='Supervisor');

INSERT INTO rol (id_cuenta,nombre,descripcion,activo,es_sistema,es_plantilla)
SELECT c.id_cuenta,'Supervisor','Autoriza excepciones de POS e inventario',1,0,0
FROM cuenta c
WHERE NOT EXISTS (SELECT 1 FROM rol r WHERE r.id_cuenta=c.id_cuenta AND r.nombre='Supervisor');

INSERT IGNORE INTO rol_permiso(id_rol,id_permiso)
SELECT r.id_rol,p.id_permiso FROM rol r JOIN permiso p ON
 (p.modulo='supervisor' AND p.accion IN ('aprobar_pos','aprobar_inventario')) OR
 (p.modulo='pos' AND p.accion IN ('cancelar_venta','devoluciones','cambiar_precios','cerrar_caja','caja','crear_promocion','asociar_cliente')) OR
 (p.modulo='inventario' AND p.accion IN ('ajustes','transferencias','conteo_fisico'))
WHERE r.nombre='Supervisor' AND r.activo=1;

COMMIT;
