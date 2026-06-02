# svprivateproducts (PrestaShop 8.2.x)

Modulo para hacer productos privados por relacion directa `cliente <-> producto` (sin usar grupos como logica principal).

## Instalacion

1. Copia la carpeta `svprivateproducts` dentro de `modules/`.
2. Instala el modulo desde Back Office.

Al instalar crea la tabla `PREFIX_sv_private_product_access`.

## Uso (Back Office)

En la configuracion del modulo puedes:

1. Crear una relacion `id_customer` + `id_product` (por tienda).
2. Ver el listado de relaciones.
3. Activar/desactivar.
4. Eliminar.

Reglas:

* Si un producto NO tiene relaciones activas en la tabla del modulo (para la tienda actual), el producto es publico.
* Si un producto tiene al menos una relacion activa, se considera privado.
* Solo los clientes con relacion activa pueden verlo/comprarlo.

## Pruebas minimas

1. Producto sin relaciones activas: visible para todos.
2. Producto con relacion activa para cliente A: cliente A lo ve en listados y puede acceder a la ficha.
3. Producto con relacion activa para cliente A: cliente B no lo ve en listados y accediendo por URL devuelve 404.
4. Producto con relacion activa para cliente A: usuario no logueado no lo ve en listados y accediendo por URL devuelve 404.
5. Cliente no autorizado no puede anadir el producto al carrito por peticion manual (URL/POST a carrito).
6. Cliente autorizado puede anadirlo si hay stock y PrestaShop lo permite.
7. Producto privado no debe aparecer en categorias/busqueda/fabricante/novedades/destacados cuando esos listados pasen por el sistema estandar de `ProductSearch`.

## Notas

* Multitienda: todas las comprobaciones usan `id_shop` del contexto.
* Seguridad: las acciones del listado (activar/desactivar/eliminar) usan token del back office y validan IDs.
* SEO: por defecto si el producto es privado y el cliente NO esta autorizado, se devuelve 404 real (no se renderiza). Opcionalmente puedes configurar redireccion 302.
* Sitemap: PrestaShop core no expone un hook universal fiable para excluir productos de todos los sitemaps de terceros. Ver TODO en el codigo.

## Doofinder

Si Doofinder sustituye el buscador, la ocultacion en busqueda no pasa por `ProductSearch` de PrestaShop, por lo que el filtrado por hook estandar no aplica a esos resultados.

Este modulo mantiene una proteccion minima:

* Si alguien accede por URL directa a un producto privado sin autorizacion: 404 (o redireccion si la configuras).
* Si intenta anadir al carrito manualmente sin autorizacion: se bloquea (404 en contexto de carrito/AJAX).
