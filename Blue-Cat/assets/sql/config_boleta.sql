-- Tabla para configuración de boletas
CREATE TABLE IF NOT EXISTS config_boleta (
    id_config INT AUTO_INCREMENT PRIMARY KEY,
    id_user INT NOT NULL,
    nombre_empresa VARCHAR(150) NOT NULL,
    rut_empresa VARCHAR(20),
    direccion VARCHAR(255),
    telefono VARCHAR(30),
    email VARCHAR(100),
    logo TEXT, -- Base64 del logo
    mensaje_pie TEXT,
    mensaje_agradecimiento TEXT DEFAULT '¡Gracias por su compra!',
    mostrar_rut_cliente TINYINT(1) DEFAULT 0,
    mostrar_desglose_iva TINYINT(1) DEFAULT 1,
    mostrar_descuento TINYINT(1) DEFAULT 1,
    iva_porcentaje DECIMAL(5,2) DEFAULT 19.00,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_user) REFERENCES usuario(id_user)
);

-- Insertar configuración por defecto para usuario 1
INSERT INTO config_boleta (id_user, nombre_empresa, rut_empresa, direccion, telefono, email, mensaje_agradecimiento, iva_porcentaje)
VALUES (1, 'MiniMarket San Fernando', '76.086.428-5', 'Av. Principal 123', '+56 9 1234 5678', 'contacto@minimarketsanfernando.cl', '¡Gracias por su compra!', 19.00);
