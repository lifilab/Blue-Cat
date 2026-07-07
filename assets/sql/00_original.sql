-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
-- Servidor: localhost:3306
-- Tiempo de generación: 21-05-2024 a las 21:26:02
-- Versión del servidor: 8.0.30
-- Versión de PHP: 8.1.10

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- Base de datos: `erp`
-- --------------------------------------------------------

-- Estructura para la tabla `detalle_pedido`
CREATE TABLE `detalle_pedido` (
  `id_detalle_pedido` int NOT NULL AUTO_INCREMENT,
  `id_pedido` int NOT NULL,
  `id_producto` int NOT NULL,
  `cantidad_pedida` int NOT NULL,
  `precio_total` int NOT NULL,
  PRIMARY KEY (`id_detalle_pedido`),
  KEY `id_pedido` (`id_pedido`),
  KEY `id_producto` (`id_producto`),
  CONSTRAINT `detalle_pedido_ibfk_1` FOREIGN KEY (`id_pedido`) REFERENCES `pedido` (`id_pedido`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `detalle_pedido_ibfk_2` FOREIGN KEY (`id_producto`) REFERENCES `producto` (`id_producto`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Estructura para la tabla `metodo_de_pago`
CREATE TABLE `metodo_de_pago` (
  `id_metodo_de_pago` int NOT NULL AUTO_INCREMENT,
  `id_pedido` int NOT NULL,
  `nombre_metodo_pago` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `monto` int NOT NULL,
  PRIMARY KEY (`id_metodo_de_pago`),
  KEY `id_pedido` (`id_pedido`),
  CONSTRAINT `metodo_de_pago_ibfk_1` FOREIGN KEY (`id_pedido`) REFERENCES `pedido` (`id_pedido`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Estructura para la tabla `pedido`
CREATE TABLE `pedido` (
  `id_pedido` int NOT NULL AUTO_INCREMENT,
  `id_sesion` int NOT NULL,
  `precio_total` int NOT NULL,
  `pago_total` int NOT NULL,
  `diferencia` int NOT NULL,
  `fecha` datetime NOT NULL,
  PRIMARY KEY (`id_pedido`),
  KEY `id_sesion` (`id_sesion`),
  CONSTRAINT `pedido_ibfk_6` FOREIGN KEY (`id_sesion`) REFERENCES `sesion` (`id_sesion`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=229;

-- Estructura para la tabla `producto`
CREATE TABLE `producto` (
  `id_producto` int NOT NULL AUTO_INCREMENT,
  `id_user` int NOT NULL,
  `nombre_producto` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `precio_venta` int DEFAULT NULL,
  `codigo_de_barras` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `cantidad` int DEFAULT NULL,
  `categoria` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  PRIMARY KEY (`id_producto`),
  KEY `id_user` (`id_user`),
  CONSTRAINT `producto_ibfk_1` FOREIGN KEY (`id_user`) REFERENCES `usuario` (`id_user`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=4568;

-- Estructura para la tabla `sesion`
CREATE TABLE `sesion` (
  `id_sesion` int NOT NULL AUTO_INCREMENT,
  `id_user` int NOT NULL,
  `fecha_ingreso` varchar(50) NOT NULL,
  `monto_apertura` int DEFAULT NULL,
  `empleado` varchar(30) DEFAULT NULL,
  `nota` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id_sesion`),
  KEY `id_user` (`id_user`),
  CONSTRAINT `sesion_ibfk_1` FOREIGN KEY (`id_user`) REFERENCES `usuario` (`id_user`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=60;

-- Estructura para la tabla `usuario`
CREATE TABLE `usuario` (
  `id_user` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(20) NOT NULL,
  `correo` varchar(50) NOT NULL,
  `password` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `validar_sesion` tinyint(1) NOT NULL,
  PRIMARY KEY (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=4;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
