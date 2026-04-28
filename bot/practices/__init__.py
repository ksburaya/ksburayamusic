from practices.base import BasePractice
from practices.listen_space import ListenSpacePractice
from practices.sound_moment import SoundMomentPractice

# Порядок важен: entry_points и fallbacks первого элемента получают приоритет
PRACTICES: list[BasePractice] = [
    ListenSpacePractice(),
    SoundMomentPractice(),
]
