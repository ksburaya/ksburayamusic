from abc import ABC, abstractmethod

from telegram.ext import Application, BaseHandler


class BasePractice(ABC):
    """
    Базовый класс для практики. Каждая практика:
    - декларирует entry_points и states для общего ConversationHandler
    - регистрирует свои scheduled jobs
    - знает, как сформировать payload для API /entries.php

    Чтобы добавить новую практику:
    1. Создать файл bot/practices/<name>.py с классом-наследником
    2. Добавить экземпляр в PRACTICES в bot/practices/__init__.py
    3. Использовать диапазон state-констант 100*N .. 100*N+99, не пересекающийся с другими практиками
    """

    practice_id: str

    @abstractmethod
    def get_entry_points(self) -> list[BaseHandler]:
        """Точки входа для ConversationHandler (команды, кнопки, callback)."""
        ...

    @abstractmethod
    def get_states(self) -> dict[int, list[BaseHandler]]:
        """Словарь состояний FSM для ConversationHandler."""
        ...

    def get_fallbacks(self) -> list[BaseHandler]:
        """Fallback-обработчики (обычно только /start у первой практики)."""
        return []

    def register_jobs(self, app: Application) -> None:
        """Регистрация задач планировщика. Переопределить при необходимости."""
        pass

    @abstractmethod
    def build_entry(self, data: dict) -> dict:
        """Формирует JSON-payload для POST /api/entries.php из накопленных данных."""
        ...
