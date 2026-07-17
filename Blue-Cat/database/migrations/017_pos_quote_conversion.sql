-- Phase 2: quotation conversion must finish through the canonical sale transaction.
SET NAMES utf8mb4;

ALTER TABLE pos_cotizacion
  ADD COLUMN id_pedido INT NULL AFTER id_cliente,
  ADD UNIQUE KEY uq_pos_cotizacion_pedido (id_pedido),
  ADD CONSTRAINT fk_pos_cotizacion_pedido FOREIGN KEY (id_pedido)
    REFERENCES pedido(id_pedido) ON DELETE RESTRICT ON UPDATE CASCADE;
