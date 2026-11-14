# Configuración de mobs compatible con VanillaMine (RyzeMP, PR #61)

RyzeMP adopta los **mismos nombres y estructura de opciones** que la sección `mobs:` de VanillaMine, para simplificar ajustes y futuras integraciones. La **compatibilidad de configuración no significa paridad del motor de juego**: este PR aún tiene un subconjunto de mobs y reglas.

## Opciones implementadas

- `mobs.enabled`, `mobs.disabled-worlds`, `max-ai-updates-per-tick`, `max-path-searches-per-tick`, `max-path-nodes`: controlan la IA y sus presupuestos (por defecto 200/10/400).
- `spawning.enabled` y `/gamerule domobspawning`: ambos deben permitir aparición. El gamerule se guarda por mundo.
- `spawning.mode: vanilla`: muestrea cada chunk de simulación con `chunk-chance` sobre 2000 por tick y `max-attempts-per-tick`; aplica `global-cap` y `spawn-limits` con zonas 9×9 chunks, separados por superficie/cueva; cuenta mobs adicionales según `count-all-mobs-for-spawning`.
- `spawn-limits`: `animal` 4 en superficie y 0 en cuevas, `monster` 8/16 y `water_animal` 36/0 cuando el valor es `-1`. Los valores positivos personalizan los caps. `ambient` y `pillager` admiten la clave aunque esas especies aún no están disponibles.
- Los calamares usan la población `animal` de su regla oficial Bedrock y comparten ese límite con el ganado; `water_animal` queda reservado para otras especies acuáticas. Las reglas incluidas aplican también límites de densidad por especie en 9×9 chunks.
- `max-distance` y `instant-despawn-distance`: distancia 3D en bloques desde jugadores; `0` usa 44 si el radio de simulación es 4 o menos, o hasta 128 para radios mayores.
- `spawning.mode: legacy`: intenta apariciones con `hostile-interval`, `passive-interval`, `hostile-cap`, `passive-cap` en vez de chunk-chance. Las poblaciones están limitadas por jugador a 64 bloques.
- Archivos `pocketmine.yml` previos que incluyan `interval-ticks`, `max-attempts`, `max-total`, `max-animals`, `max-monsters` o `max-aquatic` siguen reconociéndose. Si no se indica `mode` pero se usa `interval-ticks`, se conserva el estilo legacy. Los nombres nuevos tienen prioridad en caso de conflicto.

## Opciones reservadas/pendientes de integración

Aunque se muestran con los mismos nombres que VanillaMine, **todavía no funcionan completamente**: `vanilla-rules` (descarga y actualización de archivos oficiales Mojang), `ignore-plugin-npcs: false`, optimización `movement-broadcast`, `phantoms`, `wandering-traders`, `village-cats`, `patrols` y `raids`. Los valores predeterminados de mecanismos inexistentes son `false` y activar estas funciones produce una advertencia en consola. Las distancias de movimiento siguen siendo las originales de RyzeMP, no se silencian por esos campos.

`spawn-limits.ambient` y `spawn-limits.pillager` tienen estructura compatible pero no generan esas categorías mientras no estén implementadas. En modo `vanilla` se aplican las reglas de aparición incluidas de VanillaMine/Mojang para las ocho especies registradas (vacas, cerdos, ovejas, gallinas, calamares, zombis, esqueletos, creepers). Faltan grupos de aparición, eventos, especies adicionales, estructuras y Nether/End. La opción `vanilla-rules` todavía no cambia la fuente de reglas; el subconjunto incluido se usa automáticamente en modo `vanilla`.

## Configuración para solo animales

```yaml
mobs:
  enabled: true
  disabled-worlds: [lobby, arena]
  max-ai-updates-per-tick: 200
  max-path-searches-per-tick: 10
  max-path-nodes: 400
  spawning:
    enabled: true
    mode: vanilla
    vanilla-rules: false
    chunk-chance: 11
    max-attempts-per-tick: 32
    global-cap: 200
    count-all-mobs-for-spawning: true
    spawn-limits:
      animal: 4
      monster: 0
      ambient: 0
      water_animal: 0
      pillager: 0
    max-distance: 0
    instant-despawn-distance: 0
```

Reinicia RyzeMP tras cambiar `pocketmine.yml`. En el mundo: `/gamerule domobspawning true`. Si el mundo figura en `disabled-worlds`, el gamerule no lo habilita.

**Estado:** integrado en la rama `integration/vanillamine-mob-ai` / PR #61 en borrador. No fusionar sin CI, pruebas con clientes Bedrock reales, mundos LevelDB e impacto TPS. No modifica Engine ni HardCoreFactions.
