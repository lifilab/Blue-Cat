export function ProductDemo() {
  const bars = [42, 68, 54, 86, 73, 95, 78];
  return (
    <div className="product-window" aria-label="Vista representativa del panel operativo de Blue Cat">
      <div className="window-bar" aria-hidden="true"><i className="window-dot"/><i className="window-dot"/><i className="window-dot"/></div>
      <div className="window-shell">
        <aside className="demo-sidebar"><strong>Blue Cat</strong>{["Resumen", "POS", "Ventas", "Inventario", "Clientes"].map((label, index) => <div className={`demo-nav-item ${index === 0 ? "active" : ""}`} key={label}>{label}</div>)}</aside>
        <div className="demo-main">
          <div className="demo-top"><div><small className="muted">OPERACIÓN LOCAL</small><h3 style={{ margin: ".25rem 0 0" }}>Resumen de hoy</h3></div><span className="status">Sistema disponible</span></div>
          <div className="demo-kpis"><div className="demo-card"><span>VENTAS REGISTRADAS</span><strong>24</strong></div><div className="demo-card"><span>CAJAS ABIERTAS</span><strong>2</strong></div><div className="demo-card"><span>ALERTAS DE STOCK</span><strong>5</strong></div></div>
          <div className="demo-grid"><div className="demo-card"><span>ACTIVIDAD OPERATIVA</span><div className="chart" aria-hidden="true">{bars.map((height, index) => <i key={index} style={{ height: `${height}%` }} />)}</div></div><div className="demo-card"><span>INVENTARIO</span><div className="stock-list"><div className="stock-row"><b>Disponible</b><span>Correcto</span></div><div className="stock-row"><b>Stock crítico</b><span>Revisar</span></div><div className="stock-row"><b>Movimientos</b><span>Hoy</span></div></div></div></div>
        </div>
      </div>
    </div>
  );
}
