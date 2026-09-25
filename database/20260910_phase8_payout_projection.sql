ALTER TABLE repasses_reservas DROP CONSTRAINT repasses_reservas_status_local_check;
ALTER TABLE repasses_reservas ADD CONSTRAINT repasses_reservas_status_local_check CHECK(status_local IN ('aguardando_pagamento','aguardando_liberacao','liberado_para_repasse','processando','parcialmente_concluido','concluido','falhou','conciliacao_manual','cancelado'));
ALTER TABLE reservas DROP CONSTRAINT chk_reservas_status_repasse;
ALTER TABLE reservas ADD CONSTRAINT chk_reservas_status_repasse CHECK(status_repasse IS NULL OR status_repasse IN ('aguardando_pagamento','aguardando_liberacao','liberado_para_repasse','processando','parcialmente_concluido','concluido','falhou','conciliacao_manual','cancelado'));
